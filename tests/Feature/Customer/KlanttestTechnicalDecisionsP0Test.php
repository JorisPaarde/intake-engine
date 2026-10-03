<?php

declare(strict_types=1);

/**
 * Klanttest 2026-10-02 — P0: technische beslissingen uit de klantvragen (BL-116 / ADR-0015).
 */

use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\TechnicalDecisionKeys;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeKlanttestP0Intake(?int $templateVersion = null): Intake
{
    $user = User::factory()->create();
    $template = IntakeTemplate::query()->where('key', 'airco')->firstOrFail();
    $version = $templateVersion === null
        ? $template->latestPublishedVersion()
        : $template->versions()->where('version', $templateVersion)->firstOrFail();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Case 80 Klant',
        'customer_email' => 'case80@example.com',
    ]);
}

/**
 * @return list<string>
 */
function klanttestP0StepKeys(Intake $intake): array
{
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();

    return collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->pluck('question_key')
        ->all();
}

function findKlanttestQuestion(IntakeTemplateVersion $version, string $key): ?IntakeQuestion
{
    foreach ($version->sections as $section) {
        foreach ($section->questions as $question) {
            if ($question->key === $key) {
                return $question;
            }
        }
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function sampleKlanttestAnswer(IntakeQuestion $question): array
{
    return match ($question->type) {
        QuestionType::ShortText, QuestionType::LongText => ['text' => 'Testantwoord '.$question->key],
        QuestionType::Number => ['number' => 1],
        QuestionType::SingleChoice => ['value' => $question->options->first()?->value ?? 'yes'],
        QuestionType::MultiChoice => ['values' => [$question->options->first()?->value ?? 'a']],
        QuestionType::Boolean => ['bool' => true],
        QuestionType::Photo => ['upload_ids' => []],
    };
}

function fillCustomerFacingUntilComplete(Intake $intake): void
{
    $save = app(SaveIntakeAnswer::class);
    $store = app(StoreIntakeUpload::class);
    $checker = app(CompletenessChecker::class);
    $fixture = base_path('tests/fixtures/klanttest-20261002/woonkamer-720.jpg');

    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $intake->refresh();
        $version = $intake->templateVersion()
            ->with(['sections.questions.options', 'sections.questions.rules'])
            ->firstOrFail();
        $check = $checker->check($intake, $version);

        if ($check['is_complete']) {
            return;
        }

        foreach ($check['missing'] as $item) {
            $question = findKlanttestQuestion($version, $item['question_key']);
            if ($question === null) {
                continue;
            }

            if ($question->type === QuestionType::Photo) {
                $store->handle(
                    $intake,
                    $item['question_key'],
                    $item['section_instance_key'],
                    UploadedFile::fake()->createWithContent(
                        $item['question_key'].'.jpg',
                        (string) file_get_contents($fixture),
                    ),
                );

                continue;
            }

            $save->handle(
                $intake,
                $item['question_key'],
                $item['section_instance_key'],
                sampleKlanttestAnswer($question),
            );
        }
    }

    throw new RuntimeException('Kon klantflow niet afronden binnen 50 pogingen.');
}

test('technical decision keys are shared and hidden from the latest customer wizard', function () {
    expect(TechnicalDecisionKeys::all())->toContain(
        'natural_fall_possible',
        'pipe_route_description',
        'drillings_needed',
        'free_group_known',
    );

    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    expect($version->version)->toBe(17);

    $steps = klanttestP0StepKeys(makeKlanttestP0Intake());
    foreach (TechnicalDecisionKeys::all() as $key) {
        expect($steps)->not->toContain($key);
    }
});

test('v16 pinned intake shows drain_photo after Weet ik niet despite hidden natural_fall rule', function () {
    $intake = makeKlanttestP0Intake(16);
    expect($intake->templateVersion->version)->toBe(16);

    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, [
        'value' => 'unknown',
    ]);

    $steps = klanttestP0StepKeys($intake);

    expect($steps)->not->toContain('natural_fall_possible')
        ->and($steps)->not->toContain('pipe_route_description')
        ->and($steps)->not->toContain('drillings_needed')
        ->and($steps)->not->toContain('free_group_known')
        ->and($steps)->not->toContain('pipe_distance_indication')
        ->and($steps)->toContain('drain_photo');
});

test('case 80 reproduction: Weet ik niet on drain_location does not force natural_fall ja/nee', function () {
    $intake = makeKlanttestP0Intake();

    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, [
        'value' => 'unknown',
    ]);

    $steps = klanttestP0StepKeys($intake);
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();

    expect($steps)->not->toContain('natural_fall_possible')
        ->and($steps)->toContain('drain_photo')
        ->and($steps)->not->toContain('pipe_route_description')
        ->and($steps)->not->toContain('drillings_needed')
        ->and($steps)->not->toContain('free_group_known');

    $drainStep = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->firstWhere('question_key', 'drain_photo');

    expect($drainStep)->not->toBeNull()
        ->and($drainStep['is_required'])->toBeTrue()
        ->and($drainStep['title'])->toContain('condenswater');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    /** @var list<array{question_key: string, key: string, title: string}> $viewSteps */
    $viewSteps = $component->viewData('steps');
    $viewKeys = collect($viewSteps)->pluck('question_key')->all();

    expect($viewKeys)->not->toContain('natural_fall_possible')
        ->and($viewKeys)->toContain('drain_photo');

    $drainIndex = collect($viewSteps)->search(
        static fn (array $step): bool => $step['question_key'] === 'drain_photo',
    );
    expect($drainIndex)->not->toBeFalse();

    $component->set('stepIndex', (int) $drainIndex)
        ->set('activeStepKey', $viewSteps[(int) $drainIndex]['key'])
        ->assertSee('Foto van de plek waar condenswater weg kan')
        ->assertDontSee('Kan het condenswater waarschijnlijk zonder pomp weglopen?')
        ->assertDontSee('Welke leidingroute lijkt het meest waarschijnlijk?')
        ->assertDontSee('Zijn er waarschijnlijk gaten door muren of vloeren nodig?');
});

test('drain_photo stays visible and optional after a concrete drain_location observation', function () {
    $intake = makeKlanttestP0Intake();

    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, [
        'value' => 'outside_nearby',
    ]);

    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $drainStep = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->firstWhere('question_key', 'drain_photo');

    expect($drainStep)->not->toBeNull()
        ->and($drainStep['is_required'])->toBeFalse();
});

test('acceptance: customer can complete without inventing technical answers; installer sees open points', function () {
    $intake = makeKlanttestP0Intake();

    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, [
        'value' => 'unknown',
    ]);

    $drainFixture = base_path('tests/fixtures/klanttest-20261002/gevel-extra.jpg');
    app(StoreIntakeUpload::class)->handle(
        $intake,
        'drain_photo',
        null,
        UploadedFile::fake()->createWithContent('drain.jpg', (string) file_get_contents($drainFixture)),
    );

    fillCustomerFacingUntilComplete($intake->fresh());

    $version = $intake->fresh()->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);

    expect($check['is_complete'])->toBeTrue();

    foreach (TechnicalDecisionKeys::all() as $key) {
        expect($intake->fresh()->answers()->where('question_key', $key)->exists())->toBeFalse();
    }

    $codes = collect($check['attention_points'])->pluck('code')->all();

    expect($codes)->toContain('condensate_pump_open')
        ->and($codes)->toContain('pipe_route_open')
        ->and($codes)->toContain('drillings_open')
        ->and($codes)->toContain('electrical_provision_open');

    $completed = app(CompleteIntake::class)->handle($intake->fresh());

    expect($completed->status)->toBe(IntakeStatus::Completed)
        ->and($completed->attentionPoints->pluck('code')->all())
        ->toContain('condensate_pump_open')
        ->toContain('pipe_route_open')
        ->toContain('drillings_open')
        ->toContain('electrical_provision_open');
});

test('AI drillings_needed=false keeps drillings_open as readable proposal for the installer', function () {
    $intake = makeKlanttestP0Intake();

    FakeAiClient::alwaysReturn([
        'pipe_route_description' => 'along_facade',
        'pipe_distance_indication' => 'short',
        'drillings_needed' => 'no',
        'confidence' => 'high',
        'evidence' => 'Leiding loopt zichtbaar langs de gevel zonder nieuwe doorboring.',
        'retake_instruction' => null,
    ]);

    $routeFixture = base_path('tests/fixtures/klanttest-20261002/gevel-extra.jpg');
    app(StoreIntakeUpload::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        UploadedFile::fake()->createWithContent('route.jpg', (string) file_get_contents($routeFixture)),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        PhotoDerivationProfile::require('pipe_route'),
    );

    $drillings = $intake->fresh()->answers()->where('question_key', 'drillings_needed')->firstOrFail();
    expect($drillings->value)->toBe(['bool' => false])
        ->and($drillings->prefill_source)->toBe(DerivePhotoAnswers::SOURCE_DERIVED)
        ->and(klanttestP0StepKeys($intake))->not->toContain('drillings_needed');

    $version = $intake->fresh()->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    $drillingsPoint = collect($check['attention_points'])->firstWhere('code', 'drillings_open');

    expect($drillingsPoint)->not->toBeNull()
        ->and($drillingsPoint['label'])->toBe(
            'AI-voorstel: Nee, nog te beoordelen (afgeleid uit foto bij Leidingroute)',
        );

    // Installateursantwoord sluit het open punt niet (geen afhandelingskoppeling; BL-117).
    app(SaveIntakeAnswer::class)->handle(
        $intake->fresh(),
        'drillings_needed',
        null,
        ['bool' => false],
        'installer',
    );

    $afterInstaller = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    expect(collect($afterInstaller['attention_points'])->pluck('code')->all())
        ->toContain('drillings_open');
});

test('existing customer technical answer on pinned intake stays open with Klant gaf aan label', function () {
    $intake = makeKlanttestP0Intake(16);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'natural_fall_possible',
        null,
        ['bool' => false],
    );

    $version = $intake->fresh()->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    $point = collect($check['attention_points'])->firstWhere('code', 'condensate_pump_open');

    expect($point)->not->toBeNull()
        ->and($point['label'])->toBe('Klant gaf aan: Nee, nog te beoordelen');
});
