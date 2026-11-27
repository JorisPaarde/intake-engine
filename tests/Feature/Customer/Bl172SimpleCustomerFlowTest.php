<?php

declare(strict_types=1);

use App\Domains\AI\Actions\PrefillAnswersFromKnownContext;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\PrefillFromCustomerWishesJob;
use App\Domains\AI\Jobs\SuggestAttentionPointsJob;
use App\Domains\AI\Jobs\SummarizeIntakeJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\SimpleCustomerFlow;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
});

afterEach(function () {
    FakeAiClient::reset();
});

function bl172Intake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'eenvoudig@example.com',
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function bl172Steps(Intake $intake): array
{
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    return app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
}

/**
 * @return list<string>
 */
function bl172StepRefs(Intake $intake): array
{
    return array_map(
        static fn (array $step): string => ($step['section_instance_key'] !== null ? $step['section_instance_key'].':' : '').$step['question_key'],
        bl172Steps($intake),
    );
}

function bl172Photo(Intake $intake, string $questionKey, ?string $instanceKey): void
{
    markTestUploadSatisfied(app(StoreIntakeUpload::class)->handle(
        $intake,
        $questionKey,
        $instanceKey,
        UploadedFile::fake()->image($questionKey.'.jpg', 1280, 960),
    ));
}

test('de nieuwste airco-versie (≥ v34) zet de eenvoudige klantopname aan', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    expect($version->version)->toBeGreaterThanOrEqual(34)
        ->and(SimpleCustomerFlow::isEnabled($version))->toBeTrue();

    $questions = $version->sections->flatMap(fn ($section) => $section->questions)->keyBy('key');

    // Niets weggegooid: oude vragen bestaan nog, alleen voor de installateur.
    expect($questions->get('building_type')?->meta['audience'] ?? null)->toBe('installer')
        ->and($questions->get('cooling_heating')?->meta['audience'] ?? null)->toBe('installer')
        ->and($questions->get('drain_location')?->meta['audience'] ?? null)->toBe('installer')
        ->and($questions->get('request_reason')?->meta['audience'] ?? null)->toBeNull()
        ->and($questions->get('room_facade_side')?->meta['companion_of'] ?? null)->toBe('room_photos')
        ->and($questions->get('app_control_wanted')?->meta['audience'] ?? null)->toBe('installer');
});

test('v33 blijft de oude klantflow houden', function () {
    seedAircoTemplateVersion(33);
    $v33 = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()
        ->versions()->where('version', 33)->firstOrFail();

    expect(SimpleCustomerFlow::isEnabled($v33))->toBeFalse();
});

test('drie kamers: aanvraag, 3× kamerfoto, buitenunit, meterkast, wensen, toestemming, controlepagina', function () {
    $intake = bl172Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Airco in 3 slaapkamers van 3 bij 5, koelen en verwarmen']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 3]);

    $refs = bl172StepRefs($intake);

    expect($refs)->toBe([
        'request_reason',
        'indoor_unit_count',
        'room-1:room_photos',
        'room-2:room_photos',
        'room-3:room_photos',
        'outdoor_location_photos',
        'fusebox_photo',
        '_closing_wishes',
        'privacy_consent',
        'truth_confirmation',
    ]);

    $steps = collect(bl172Steps($intake));
    $roomPhoto = $steps->first(fn (array $step): bool => $step['question_key'] === 'room_photos');
    $wishes = $steps->firstWhere('question_key', '_closing_wishes');

    expect($roomPhoto['companion_question_keys'] ?? [])->toBe(['room_facade_side'])
        ->and($steps->contains(fn (array $step): bool => ($step['kind'] ?? '') === 'known_summary'))->toBeFalse()
        ->and($wishes['bundle_question_keys'])->toBe(['additional_comments'])
        ->and($wishes['title'])->toBe('Heb je nog wensen?')
        ->and($wishes['dictation_hint'] ?? false)->toBeTrue();
});

test('kamertal uit de aanvraagtekst slaat de aantal-vraag over', function () {
    $intake = bl172Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Airco in 2 slaapkamers']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 2], PrefillSources::AI_TEXT, 'stated', 95, '2 slaapkamers', 'klantantwoord');

    expect(bl172StepRefs($intake))->not->toContain('indoor_unit_count')
        ->and(bl172StepRefs($intake))->toContain('room-2:room_photos');
});

test('voorbeeldvragen tonen alleen wat nog onbekend is', function () {
    $intake = bl172Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $wishes = collect(bl172Steps($intake))->firstWhere('question_key', '_closing_wishes');
    expect($wishes['wish_prompts'])->toHaveCount(4)
        ->and($wishes['wish_prompts'][0])->toContain('koelen');

    // Koelen/verwarmen al bekend uit de aanvraagtekst → die voorbeeldvraag vervalt.
    $save->handle($intake, 'cooling_heating', null, ['value' => 'both'], PrefillSources::AI_TEXT, 'stated', 95, 'koelen en verwarmen', 'klantantwoord');
    $wishes = collect(bl172Steps($intake))->firstWhere('question_key', '_closing_wishes');

    expect(collect($wishes['wish_prompts'])->contains(fn (string $prompt): bool => str_contains($prompt, 'koelen')))->toBeFalse()
        ->and($wishes['wish_prompts'])->toHaveCount(4)
        ->and($wishes['wish_prompts'][0])->toContain('app');

    // Een onbevestigde AI-suggestie telt niet als bekend.
    $save->handle($intake, 'app_control_wanted', null, ['value' => 'yes'], PrefillSources::AI_TEXT_SUGGESTION);
    $wishes = collect(bl172Steps($intake))->firstWhere('question_key', '_closing_wishes');
    expect($wishes['wish_prompts'][0])->toContain('app');
});

test('klantdeel is compleet zonder de installateursvragen', function () {
    $intake = bl172Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Airco in de woonkamer']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    bl172Photo($intake, 'room_photos', 'room-1');
    bl172Photo($intake, 'outdoor_location_photos', null);
    bl172Photo($intake, 'fusebox_photo', null);
    $save->handle($intake, 'truth_confirmation', null, ['bool' => true]);
    $save->handle($intake, 'privacy_consent', null, ['bool' => true]);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);

    // cooling_heating, building_type, room_type … zijn verplicht in het template maar nu installateurswerk.
    expect($check['missing'])->toBe([])
        ->and($check['is_complete'])->toBeTrue();
});

test('gevelkant staat op het kamerfotoscherm en blijft daar na kiezen', function () {
    $intake = bl172Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Airco in de slaapkamer']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $component->call('next')->call('next');

    $component->assertSee('Foto van de ruimte')
        ->assertSee('Aan welke kant van het huis ligt deze ruimte?')
        ->assertSee('Voorkant (straatkant)')
        ->set('form.room-1__room_facade_side.value', 'back')
        ->assertSee('Foto van de ruimte');

    $answer = IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_facade_side')
        ->where('section_instance_key', 'room-1')
        ->first();

    expect($answer?->value)->toBe(['value' => 'back'])
        ->and($answer?->prefill_source)->toBeNull();
});

test('wensenscherm toont voorbeeldvragen en dicteertip', function () {
    $intake = bl172Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Airco in de woonkamer, alleen koelen']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    bl172Photo($intake, 'room_photos', 'room-1');
    bl172Photo($intake, 'outdoor_location_photos', null);
    bl172Photo($intake, 'fusebox_photo', null);

    $steps = bl172Steps($intake);
    $wishIndex = collect($steps)->search(fn (array $step): bool => $step['question_key'] === '_closing_wishes');
    $intake->update(['current_question_key' => $steps[$wishIndex]['key']]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $wishIndex)
        ->assertSee('Heb je nog wensen?')
        ->assertSee('Denk bijvoorbeeld aan:')
        ->assertSee('app op je telefoon')
        ->assertSee('microfoon op je toetsenbord');
});

test('versturen met wensen zet eerst de wensen-prefill in de keten', function () {
    Bus::fake();
    $intake = bl172Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Airco in de woonkamer']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    bl172Photo($intake, 'room_photos', 'room-1');
    bl172Photo($intake, 'outdoor_location_photos', null);
    bl172Photo($intake, 'fusebox_photo', null);
    $save->handle($intake, 'additional_comments', null, ['text' => 'Graag bedienen via een app op mijn telefoon.']);
    $save->handle($intake, 'truth_confirmation', null, ['bool' => true]);
    $save->handle($intake, 'privacy_consent', null, ['bool' => true]);

    $completed = app(CompleteIntake::class)->handle($intake->fresh());

    Bus::assertChained([
        new PrefillFromCustomerWishesJob($completed->id),
        new SummarizeIntakeJob($completed->id),
        new SuggestAttentionPointsJob($completed->id),
        new SynthesizeSurveyDossierJob($completed->id),
    ]);
});

test('wensen-prefill na versturen vult feiten uit de wensentekst, maar geen nieuwe ruimtes', function () {
    config(['ai.provider' => 'fake', 'ai.text_inference.enabled' => true]);
    $intake = bl172Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Airco in de woonkamer']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'additional_comments', null, ['text' => 'Graag bedienen via een app. Binnenunit boven de deur.']);
    $intake->update(['status' => IntakeStatus::Completed, 'completed_at' => now()]);

    FakeAiClient::respondUsing(function ($request): array {
        expect($request->input['known_context']['customer_wishes'] ?? null)
            ->toBe('Graag bedienen via een app. Binnenunit boven de deur.');

        return [
            'evidence' => 'Graag bedienen via een app.',
            'fills' => [
                ['question_key' => 'app_control_wanted', 'section_instance_key' => null, 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['value' => 'yes'], 'evidence' => 'via een app'],
                ['question_key' => 'preferred_indoor_location', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['text' => 'boven de deur'], 'evidence' => 'boven de deur'],
                ['question_key' => 'preferred_indoor_location', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['text' => 'boven de deur'], 'evidence' => 'boven de deur'],
                ['question_key' => 'indoor_unit_count', 'section_instance_key' => null, 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['number' => 2], 'evidence' => 'woonkamer'],
            ],
        ];
    });

    (new PrefillFromCustomerWishesJob($intake->id))->handle(app(PrefillAnswersFromKnownContext::class));

    $answers = IntakeAnswer::query()->where('intake_id', $intake->id)->get();
    $appControl = $answers->first(fn (IntakeAnswer $a): bool => $a->question_key === 'app_control_wanted');
    $location = $answers->first(fn (IntakeAnswer $a): bool => $a->question_key === 'preferred_indoor_location' && $a->section_instance_key === 'room-1');
    $count = $answers->first(fn (IntakeAnswer $a): bool => $a->question_key === 'indoor_unit_count');

    // Stated-evidence uit de wensentekst wordt geaccepteerd (niet afgewaardeerd tot inferred).
    expect($appControl?->value)->toBe(['value' => 'yes'])
        ->and($appControl?->fact_provenance)->toBe('stated')
        ->and($location?->value)->toBe(['text' => 'boven de deur'])
        ->and($answers->contains(fn (IntakeAnswer $a): bool => $a->section_instance_key === 'room-2'))->toBeFalse()
        ->and($count?->value)->toBe(['number' => 1])
        ->and($intake->fresh()->status)->toBe(IntakeStatus::Completed)
        ->and($intake->fresh()->progress_percent)->toBe($intake->progress_percent);
});

test('wensen-prefill doet niets na de installateursreview', function () {
    config(['ai.provider' => 'fake', 'ai.text_inference.enabled' => true]);
    $intake = bl172Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Airco in de woonkamer']);
    $save->handle($intake, 'additional_comments', null, ['text' => 'Graag via een app.']);
    $intake->update(['status' => IntakeStatus::Completed, 'completed_at' => now(), 'reviewed_at' => now()]);

    expect(app(PrefillAnswersFromKnownContext::class)->handle($intake->fresh(), afterCustomerSubmit: true))->toBeNull();
});
