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
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\PdokAerialImageService;
use App\Domains\Intake\Support\CustomerFindings;
use App\Domains\Intake\Support\PrefillSources;
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

function bl174Intake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'controle@example.com',
    ]);
}

/**
 * Klantdeel klaar op het versturen na; geeft de stap-index van de controlepagina.
 */
function bl174ReadyForReview(Intake $intake): int
{
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Airco in de slaapkamer van 3 bij 5, koelen en verwarmen']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    foreach ([['room_photos', 'room-1'], ['outdoor_location_photos', null], ['fusebox_photo', null]] as [$key, $instance]) {
        markTestUploadSatisfied(app(StoreIntakeUpload::class)->handle(
            $intake,
            $key,
            $instance,
            UploadedFile::fake()->image($key.'.jpg', 1280, 960),
        ));
    }
    $save->handle($intake, 'privacy_consent', null, ['bool' => true]);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);

    return (int) collect($steps)->search(fn (array $step): bool => $step['question_key'] === 'truth_confirmation');
}

test('v35: versturen is de controlepagina met correctieveld, geen extra stap', function () {
    $intake = bl174Intake();
    bl174ReadyForReview($intake);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $steps = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version));
    $review = $steps->firstWhere('question_key', 'truth_confirmation');

    expect($version->version)->toBe(35)
        ->and($review['title'])->toBe('Dit hebben we gevonden')
        ->and($review['review_page'] ?? false)->toBeTrue()
        ->and($review['companion_question_keys'] ?? [])->toBe(['customer_corrections'])
        ->and($steps->pluck('question_key')->all())->not->toContain('customer_corrections');
});

test('bevindingen in gewone taal met herkomst; onbekend en interne keys blijven weg', function () {
    $intake = bl174Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 2], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_area_m2', 'room-1', ['number' => 15], PrefillSources::DERIVED_LXW);
    $save->handle($intake, 'room_facade_side', 'room-1', ['value' => 'back']);
    $save->handle($intake, 'room_type', 'room-2', ['value' => 'living_room'], PrefillSources::AI_PHOTO);
    $save->handle($intake, 'cooling_heating', null, ['value' => 'both'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'app_control_wanted', null, ['value' => 'yes'], PrefillSources::AI_TEXT_SUGGESTION);
    $save->handle($intake, 'ownership', null, ['value' => 'unknown'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'build_year', null, ['number' => 1978], 'bag');

    $findings = CustomerFindings::forIntake($intake->fresh());
    $groups = collect($findings['groups'])->keyBy('title');
    $flat = collect($findings['groups'])->flatMap(fn (array $group) => $group['items'])->keyBy('key');

    expect($groups->keys()->all())->toBe(['Je woning', 'Je ruimtes', 'Je wensen'])
        ->and($flat['home_bouwjaar']['value'])->toBe('1978')
        ->and($flat['room_1']['label'])->toBe('Ruimte 1')
        ->and($flat['room_1']['value'])->toContain('ongeveer 15 m²')
        ->and($flat['room_1']['value'])->toContain('achterkant')
        ->and($flat['room_2']['source'])->toBe('uit je foto')
        ->and($flat['cooling_heating']['source'])->toBe('uit je tekst')
        ->and($flat['app_control_wanted']['source'])->toBe('uit je tekst (geschat)')
        ->and($flat->has('ownership'))->toBeFalse()
        ->and(json_encode($findings['groups']))->not->toContain('bedroom');
});

test('controlepagina toont bevindingen, foto’s en bewaart een correctie zonder van stap te wisselen', function () {
    $intake = bl174Intake();
    $reviewIndex = bl174ReadyForReview($intake);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $reviewIndex)
        ->assertSee('Dit hebben we gevonden')
        ->assertSee('Je ruimtes')
        ->assertSee('Je foto’s')
        ->assertSee('Klopt niet?')
        ->assertSee('Wat klopt er niet?')
        ->assertSee('Ik heb alles naar waarheid ingevuld.')
        ->set('form.customer_corrections.text', 'Ruimte: dat is een werkkamer')
        ->assertSee('Dit hebben we gevonden');

    $answer = IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'customer_corrections')
        ->first();

    expect($answer?->value)->toBe(['text' => 'Ruimte: dat is een werkkamer'])
        ->and($answer?->prefill_source)->toBeNull();
});

test('luchtfoto alleen via de eigen klantlink', function () {
    $intake = bl174Intake();
    $disk = (string) config('filesystems.media', 'local');
    Storage::disk($disk)->put('aerial/test.jpg', 'jpeg-bytes');
    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'aerial_image',
        'label' => 'Luchtfoto',
        'value' => ['media_disk' => $disk, 'media_path' => 'aerial/test.jpg', 'mime_type' => 'image/jpeg'],
        'source' => PdokAerialImageService::sourceName(),
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    $this->get(route('customer.aerial.show', ['token' => $intake->access_token]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    $other = bl174Intake();
    $this->get(route('customer.aerial.show', ['token' => $other->access_token]))->assertNotFound();

    expect(CustomerFindings::forIntake($intake->fresh())['has_aerial'])->toBeTrue()
        ->and(CustomerFindings::forIntake($other->fresh())['has_aerial'])->toBeFalse();
});

test('alleen een correctie (zonder wensen) zet de tekst-prefill in de keten', function () {
    Bus::fake();
    $intake = bl174Intake();
    bl174ReadyForReview($intake);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'customer_corrections', null, ['text' => 'Ruimte 1: werkkamer, geen slaapkamer']);
    $save->handle($intake, 'truth_confirmation', null, ['bool' => true]);

    $completed = app(CompleteIntake::class)->handle($intake->fresh());

    Bus::assertChained([
        new PrefillFromCustomerWishesJob($completed->id),
        new SummarizeIntakeJob($completed->id),
        new SuggestAttentionPointsJob($completed->id),
        new SynthesizeSurveyDossierJob($completed->id),
    ]);
});

test('correctie gaat vóór een eerdere AI-afleiding', function () {
    config(['ai.provider' => 'fake', 'ai.text_inference.enabled' => true]);
    $intake = bl174Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Airco in de slaapkamer']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT, 'stated', 95, 'slaapkamer', 'klantantwoord');
    $save->handle($intake, 'customer_corrections', null, ['text' => 'Ruimte: dat is een werkkamer']);
    $intake->update(['status' => IntakeStatus::Completed, 'completed_at' => now()]);

    FakeAiClient::respondUsing(function ($request): array {
        expect($request->input['known_context']['customer_corrections'] ?? null)->toBe('Ruimte: dat is een werkkamer');

        return [
            'evidence' => 'dat is een werkkamer',
            'fills' => [
                ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['value' => 'office'], 'evidence' => 'werkkamer'],
            ],
        ];
    });

    app(PrefillAnswersFromKnownContext::class)->handle($intake->fresh(), afterCustomerSubmit: true);

    $roomType = IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_type')
        ->where('section_instance_key', 'room-1')
        ->first();

    expect($roomType?->value)->toBe(['value' => 'office'])
        ->and($roomType?->fact_provenance)->toBe('stated');
});
