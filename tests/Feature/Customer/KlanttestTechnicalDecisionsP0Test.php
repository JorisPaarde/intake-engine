<?php

declare(strict_types=1);

/**
 * Klanttest 2026-10-02 — P0: technische beslissingen uit de klantvragen.
 *
 * Reproductie case 80: Condensafvoer → "Weet ik niet" mag niet leiden tot een
 * verplichte ja/nee over condenspomp. Acceptatie: klant rondt af zonder verzonnen
 * techniek; open punten blijven voor de installateur zichtbaar.
 */

use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\IntakeStepBuilder;
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
});

function makeKlanttestP0Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

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
                $fixture = base_path('tests/fixtures/klanttest-20261002/woonkamer-720.jpg');
                $upload = is_file($fixture)
                    ? UploadedFile::fake()->createWithContent($item['question_key'].'.jpg', (string) file_get_contents($fixture))
                    : UploadedFile::fake()->image($item['question_key'].'.jpg', 640, 480);

                $store->handle(
                    $intake,
                    $item['question_key'],
                    $item['section_instance_key'],
                    $upload,
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

test('latest airco template marks technical decisions as installer-only', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    expect($version->version)->toBe(17);

    $keys = [
        'natural_fall_possible',
        'pipe_route_description',
        'pipe_distance_indication',
        'drillings_needed',
        'free_group_known',
    ];

    foreach ($keys as $key) {
        $question = findKlanttestQuestion($version, $key);
        expect($question)->not->toBeNull()
            ->and($question->meta['installer_decision'] ?? null)->toBeTrue()
            ->and($question->is_required)->toBeFalse();
    }

    $steps = klanttestP0StepKeys(makeKlanttestP0Intake());
    foreach ($keys as $key) {
        expect($steps)->not->toContain($key);
    }
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

    // Livewire: geen verplichte ja/nee over pomp na "Weet ik niet".
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

test('acceptance: customer can complete without inventing technical answers; installer sees open points', function () {
    $intake = makeKlanttestP0Intake();

    // Reproduceer: afvoer onbekend → foto i.p.v. pomp-ja/nee; rest klantgericht invullen.
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, [
        'value' => 'unknown',
    ]);

    $fixture = base_path('tests/fixtures/klanttest-20261002/gevel-extra.jpg');
    $drainUpload = is_file($fixture)
        ? UploadedFile::fake()->createWithContent('drain.jpg', (string) file_get_contents($fixture))
        : UploadedFile::fake()->image('drain.jpg', 800, 600);

    app(StoreIntakeUpload::class)->handle($intake, 'drain_photo', null, $drainUpload);

    fillCustomerFacingUntilComplete($intake->fresh());

    $version = $intake->fresh()->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);

    expect($check['is_complete'])->toBeTrue();

    // Geen stilzwijgende ja/nee-defaults op technische velden.
    foreach (['natural_fall_possible', 'pipe_route_description', 'drillings_needed', 'free_group_known'] as $key) {
        expect($intake->fresh()->answers()->where('question_key', $key)->exists())->toBeFalse();
    }

    $codes = collect($check['attention_points'])->pluck('code')->all();

    expect($codes)->toContain('condensate_pump_open')
        ->and($codes)->toContain('pipe_route_open')
        ->and($codes)->toContain('drillings_open')
        ->and($codes)->toContain('electrical_provision_open')
        ->and($codes)->not->toContain('condensate_pump_likely');

    $completed = app(CompleteIntake::class)->handle($intake->fresh());

    expect($completed->status)->toBe(IntakeStatus::Completed)
        ->and($completed->attentionPoints->pluck('code')->all())
        ->toContain('condensate_pump_open')
        ->toContain('pipe_route_open')
        ->toContain('drillings_open')
        ->toContain('electrical_provision_open');
});
