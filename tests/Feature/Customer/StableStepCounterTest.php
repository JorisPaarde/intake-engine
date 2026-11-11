<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PhotoOverridePolicy;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function stableCounterIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Teller Test',
        'customer_email' => 'teller@example.com',
    ], $overrides));
}

test('teller toont geen van-N; na ruimtes alleen voortgangsbalk/%', function () {
    $intake = stableCounterIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);

    expect($component->viewData('stepDisplayTotalReady'))->toBeFalse()
        ->and($component->html())->toContain('data-testid="step-counter"')
        ->and($component->html())->toContain('Vraag '.$component->viewData('stepDisplayNumber'))
        ->and($component->html())->not->toContain(
            'Vraag '.$component->viewData('stepDisplayNumber').' van ',
        );

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 3], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-2', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-3', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $html = $component->html();

    expect($component->viewData('stepDisplayTotalReady'))->toBeTrue()
        ->and($html)->toContain('data-testid="progress-percent"')
        ->and($html)->toContain('data-testid="step-counter"')
        ->and($html)->not->toMatch('/Vraag \d+ van \d+/')
        ->and($html)->toContain('Voortgang via balk')
        // Geen losse "SECTIE ·" zonder zichtbare teller erna.
        ->and($html)->not->toMatch('/text-\[#838c86\]">·<\/span>\s*<span[^>]*data-testid="step-counter"/');

    // Herladen: nog steeds geen "X van Y" (stabiel t.o.v. oplopend/krimpend totaal).
    $reloaded = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    expect($reloaded->html())->not->toMatch('/Vraag \d+ van \d+/');
});

test('teller hergebruikt nummer als tussenstap wegvalt (geen gat 25→27)', function () {
    $intake = stableCounterIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 2], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-2', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');

    $firstKey = $steps[0]['key'];
    $secondKey = $steps[1]['key'] ?? null;
    expect($secondKey)->toBeString();

    $component->set('stepIndex', 0)->set('activeStepKey', $firstKey);
    expect((int) $component->viewData('stepDisplayNumber'))->toBe(1);

    // Locked props: zet via reflection (simuleert ordinal van een later weggevallen stap).
    $wizard = $component->instance();
    $ordinals = new ReflectionProperty($wizard, 'stepDisplayOrdinals');
    $seq = new ReflectionProperty($wizard, 'stepOrdinalSeq');
    $ordinals->setValue($wizard, [
        $firstKey => 1,
        'rooms::room-99::gone_step' => 2,
    ]);
    $seq->setValue($wizard, 2);

    $component->set('stepIndex', 1)->set('activeStepKey', $secondKey);
    // Prune verwijdert gone_step → seq=1 → secondKey krijgt 2 (geen sprong naar 3).
    expect((int) $component->viewData('stepDisplayNumber'))->toBe(2)
        ->and($component->get('stepDisplayOrdinals'))->not->toHaveKey('rooms::room-99::gone_step')
        ->and($component->get('stepDisplayOrdinals')[$secondKey] ?? null)->toBe(2);
});

test('zelfde stap houdt display-nummer bij krimpende lijst', function () {
    $intake = stableCounterIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 2], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-2', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    $target = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'preferred_indoor_location'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    expect($target)->toBeInt();

    $component
        ->set('stepIndex', $target)
        ->set('activeStepKey', $steps[$target]['key']);

    $numberBefore = (int) $component->viewData('stepDisplayNumber');
    $key = $steps[$target]['key'];

    foreach (['ceiling_height_m' => ['number' => 2.5], 'sun_exposure' => ['value' => 'medium'], 'glass_amount' => ['value' => 'medium'], 'glazing_type' => ['value' => 'double']] as $q => $payload) {
        app(SaveIntakeAnswer::class)->handle($intake, $q, 'room-1', $payload, PrefillSources::AI_TEXT);
        app(SaveIntakeAnswer::class)->handle($intake, $q, 'room-2', $payload, PrefillSources::AI_TEXT);
    }

    $component
        ->call('$refresh')
        ->set('activeStepKey', $key);

    expect((int) $component->viewData('stepDisplayNumber'))->toBe($numberBefore)
        ->and($component->get('stepDisplayOrdinals')[$key] ?? null)->toBe($numberBefore);
});

test('Toch doorgaan toont feedback en advanceAfterPhotoOverride gaat verder', function () {
    $intake = stableCounterIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'living_room'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_name', 'room-1', ['text' => 'Woonkamer'], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('rejected-stable.jpg', 1200, 900),
    );
    $upload->updateQuietly([
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::wrongSubject(
            PhotoSubject::Room,
            PhotoSubject::OutdoorUnit,
        )->toArray(),
    ]);

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh() ?? $intake,
        $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );
    $roomIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'room_photos'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    expect($roomIndex)->toBeInt();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $roomIndex)
        ->set('activeStepKey', $steps[$roomIndex]['key'])
        ->call('acceptPhotoMismatch')
        ->assertSet('saveMessage', PhotoOverridePolicy::OVERRIDE_ACCEPTED_FLASH)
        ->assertSet('photoOverrideAdvancePending', true)
        ->assertSet('activeStepKey', $steps[$roomIndex]['key']);

    expect($upload->fresh()->contentAssessment()?->customerAcceptedOverride())->toBeTrue();

    $component
        ->call('advanceAfterPhotoOverride')
        ->assertSet('photoOverrideAdvancePending', false)
        ->assertSet('activeStepKey', function (string $key) use ($steps, $roomIndex): bool {
            return $key !== $steps[$roomIndex]['key'];
        });
});
