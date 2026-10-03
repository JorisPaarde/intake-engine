<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreFollowUpUpload;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Actions\SubmitIntakeReview;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\FollowUpProgressCalculator;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Enums\QuestionType;
use App\Enums\ReviewDecision;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function makeP2ProgressIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Klanttest QA',
        'customer_email' => 'klanttest@example.com',
        'address_line' => 'Teststraat 1',
        'address_city' => 'Utrecht',
    ]);
}

function p2FindQuestion(IntakeTemplateVersion $version, string $key): ?IntakeQuestion
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

/** @return array<string, mixed> */
function p2SampleAnswer(IntakeQuestion $question): array
{
    return match ($question->type) {
        QuestionType::Boolean => ['bool' => false],
        QuestionType::Number => ['number' => 1],
        QuestionType::SingleChoice => ['value' => $question->options->first()?->value ?? 'unknown'],
        QuestionType::MultiChoice => ['values' => array_filter([$question->options->first()?->value])],
        default => ['text' => 'ingevuld'],
    };
}

function fillKlanttestIntakeUntilComplete(Intake $intake): void
{
    $save = app(SaveIntakeAnswer::class);
    $store = app(StoreIntakeUpload::class);
    $checker = app(CompletenessChecker::class);

    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    for ($attempt = 0; $attempt < 40; $attempt++) {
        $intake->refresh();
        $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
        $check = $checker->check($intake, $version);

        if ($check['is_complete']) {
            return;
        }

        foreach ($check['missing'] as $item) {
            $question = p2FindQuestion($version, $item['question_key']);

            if ($question === null) {
                continue;
            }

            if ($question->type === QuestionType::Photo) {
                $store->handle(
                    $intake,
                    $item['question_key'],
                    $item['section_instance_key'],
                    UploadedFile::fake()->image($item['question_key'].'.jpg', 640 + $attempt, 480 + $attempt),
                );

                continue;
            }

            $save->handle(
                $intake,
                $item['question_key'],
                $item['section_instance_key'],
                p2SampleAnswer($question),
            );
        }
    }
}

function makeP2FollowUpIntake(array $items): Intake
{
    Mail::fake();
    Queue::fake();
    config(['mail.default' => 'smtp']);

    $intake = makeP2ProgressIntake();
    fillKlanttestIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());
    $reviewer = User::factory()->create(['company_id' => $intake->company_id]);

    app(SubmitIntakeReview::class)->handle($intake->fresh(), $reviewer, [
        'decision' => ReviewDecision::NeedMoreInfo,
        'follow_up_items' => $items,
    ]);

    return $intake->fresh();
}

function p2FixtureUpload(string $name = 'woonkamer-720.jpg'): UploadedFile
{
    $fixture = base_path('tests/fixtures/klanttest-20261002/'.$name);
    expect(is_file($fixture))->toBeTrue();

    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($fixture));
}

function p2BrightUpload(string $name = 'bright.jpg', int $width = 1280, int $height = 960): UploadedFile
{
    $img = imagecreatetruecolor($width, $height);
    imagefill($img, 0, 0, imagecolorallocate($img, 220, 220, 210));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

test('lege foto-opdracht in follow-up start op 0% niet op 100%', function () {
    $intake = makeP2FollowUpIntake([
        ['type' => FollowUpItemType::Photo, 'prompt' => 'Maak een foto van de meterkast.'],
    ]);

    $round = $intake->followUpRounds()->with('items')->firstOrFail();
    $progress = app(FollowUpProgressCalculator::class)->calculate($round->items);

    expect($progress['percent'])->toBe(0)
        ->and($progress['completed'])->toBe(0)
        ->and($progress['total'])->toBe(1);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('followUpMode', true)
        ->assertSee('Onderdeel 1 van 1')
        ->assertSeeHtml('data-testid="follow-up-progress-percent">0%')
        ->assertSee('0 van 1 onderdelen afgerond')
        ->assertSee('Status: Nog te doen');
});

test('follow-up progress wordt 100% alleen na bruikbare beoordeling', function () {
    $intake = makeP2FollowUpIntake([
        ['type' => FollowUpItemType::Photo, 'prompt' => 'Maak een foto van de meterkast.'],
    ]);
    $round = $intake->followUpRounds()->with('items.uploads')->firstOrFail();
    $item = $round->items->firstOrFail();

    $before = app(FollowUpProgressCalculator::class)->calculate($round->items);
    expect($before['percent'])->toBe(0);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, p2BrightUpload())
        ->assertSet('followUpMode', true)
        ->assertSet('uploadPhase', 'assessing')
        ->assertJs('$wire.assessPendingUploads()');

    $during = app(FollowUpProgressCalculator::class)->calculate(collect([$item->fresh()->load('uploads')]));
    expect($during['percent'])->toBe(0)
        ->and($during['item_statuses'][$item->id]['label'])->toBe('Ontvangen, wordt beoordeeld');

    $component->call('assessPendingUploads');
    $item->refresh()->load('uploads');
    expect($item->uploads->first()?->usability_verdict?->isUsable())->toBeTrue();

    $component->call('completeFollowUp')->assertSet('completed', true);

    $item->refresh()->load('uploads');
    $after = app(FollowUpProgressCalculator::class)->calculate(collect([$item]));

    expect($after['percent'])->toBe(100)
        ->and($item->uploads)->not->toBeEmpty();
});

test('onbruikbare follow-upfoto telt niet mee voor 100%', function () {
    $intake = makeP2FollowUpIntake([
        ['type' => FollowUpItemType::Photo, 'prompt' => 'Maak een foto van de meterkast.'],
    ]);
    $item = $intake->followUpRounds()->with('items')->firstOrFail()->items->firstOrFail();

    $dark = imagecreatetruecolor(1280, 960);
    imagefill($dark, 0, 0, imagecolorallocate($dark, 8, 8, 8));
    ob_start();
    imagejpeg($dark, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($dark);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, UploadedFile::fake()->createWithContent('dark.jpg', $bytes))
        ->call('assessPendingUploads');

    $item->refresh()->load('uploads');
    $progress = app(FollowUpProgressCalculator::class)->calculate(collect([$item]));

    expect($progress['percent'])->toBe(0)
        ->and($progress['item_statuses'][$item->id]['status'])->toBe('unusable')
        ->and($progress['item_statuses'][$item->id]['label'])->toBe('Nieuwe foto nodig');
});

test('progress calculator bereikt 100% alleen als CompletenessChecker compleet is', function () {
    $intake = makeP2ProgressIntake();
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $progress = app(ProgressCalculator::class)->calculate($intake, $version);
    $check = app(CompletenessChecker::class)->check($intake, $version);

    expect($progress['percent'])->toBeLessThan(100)
        ->and($check['is_complete'])->toBeFalse();

    fillKlanttestIntakeUntilComplete($intake);
    $intake->refresh();
    $version = $version->fresh(['sections.questions.options', 'sections.questions.rules']);
    $progress = app(ProgressCalculator::class)->calculate($intake, $version);
    $check = app(CompletenessChecker::class)->check($intake, $version);

    expect($progress['percent'])->toBe(100)
        ->and($check['is_complete'])->toBeTrue()
        ->and($progress['missing_required'])->toBe([]);
});

test('na opslaan staat uploadPhase assessing en js-effect in de Livewire-response', function () {
    $intake = makeP2ProgressIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.fusebox_photo', p2FixtureUpload())
        ->assertSet('uploadPhase', 'assessing')
        ->assertSet('uploadPhaseMessage', 'Foto beoordelen…')
        ->assertSet('uploadPhaseComposite', 'fusebox_photo')
        ->assertJs('$wire.assessPendingUploads()');

    expect($intake->fresh()->uploads()->count())->toBe(1);
});

test('follow-up round-trip 1 bevat ook het assessPendingUploads js-effect', function () {
    $intake = makeP2FollowUpIntake([
        ['type' => FollowUpItemType::Photo, 'prompt' => 'Maak een foto van de meterkast.'],
    ]);
    $item = $intake->followUpRounds()->with('items')->firstOrFail()->items->firstOrFail();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, UploadedFile::fake()->image('meterkast.jpg', 800, 600))
        ->assertSet('uploadPhase', 'assessing')
        ->assertJs('$wire.assessPendingUploads()');
});

test('dubbele upload met dezelfde inhoud toont melding en requeued zonder verdict', function () {
    $intake = makeP2ProgressIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $contents = (string) file_get_contents(base_path('tests/fixtures/klanttest-20261002/woonkamer-720.jpg'));

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.fusebox_photo', UploadedFile::fake()->createWithContent('a.jpg', $contents))
        ->assertSet('uploadPhase', 'assessing')
        ->call('assessPendingUploads')
        ->assertSet('uploadPhase', '');

    expect($intake->fresh()->uploads()->count())->toBe(1);

    $component
        ->set('photoFiles.fusebox_photo', UploadedFile::fake()->createWithContent('b.jpg', $contents))
        ->assertSet('saveMessage', 'Deze foto staat er al')
        ->assertSet('uploadPhase', '');

    expect($intake->fresh()->uploads()->count())->toBe(1);
});

test('mount herstart beoordeling voor uploads zonder verdict', function () {
    $intake = makeP2ProgressIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        p2FixtureUpload(),
    );

    expect($upload->usability_verdict)->toBeNull();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('uploadPhase', 'assessing')
        ->assertSet('uploadPhaseComposite', 'fusebox_photo')
        ->assertJs('$wire.assessPendingUploads()');

    $pending = $component->get('pendingAssessUploadIds');
    expect($pending)->toHaveKey('fusebox_photo')
        ->and($pending['fusebox_photo'])->toContain($upload->id);
});

test('retry na mislukte beoordeling herbeoordeelt via tweede round-trip', function () {
    $intake = makeP2ProgressIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => 'Timeout-proof']);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.fusebox_photo', p2FixtureUpload())
        ->assertSet('uploadPhase', 'assessing');

    $pending = $component->get('pendingAssessUploadIds');
    expect($pending)->toHaveKey('fusebox_photo')->and($pending['fusebox_photo'])->not->toBeEmpty();

    $component
        ->set('uploadPhase', 'failed')
        ->set('uploadPhaseMessage', 'Beoordelen mislukt. Je foto en antwoorden blijven bewaard.')
        ->call('retryFailedUploadPhase')
        ->assertSet('uploadPhase', 'assessing')
        ->assertJs('$wire.assessPendingUploads()')
        ->call('assessPendingUploads')
        ->assertSet('uploadPhase', '');

    expect($intake->fresh()->uploads()->count())->toBe(1)
        ->and($intake->fresh()->answers()->where('question_key', 'request_reason')->value('value'))
        ->toMatchArray(['text' => 'Timeout-proof']);
});

test('ontbrekend mediabestand krijgt fallback-verdict en recovery queuet niet opnieuw', function () {
    $intake = makeP2ProgressIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        p2FixtureUpload(),
    );

    Storage::disk((string) $upload->disk)->delete((string) $upload->path);
    expect(Storage::disk((string) $upload->disk)->exists((string) $upload->path))->toBeFalse()
        ->and($upload->fresh()->usability_verdict)->toBeNull();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('uploadPhase', 'assessing')
        ->call('assessPendingUploads')
        ->assertSet('uploadPhase', '');

    expect($upload->fresh()->usability_verdict)->toBe(PhotoUsabilityVerdict::Ok)
        ->and($upload->fresh()->usability_verdict)->not->toBeNull();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('uploadPhase', '')
        ->assertSet('pendingAssessUploadIds', []);
});

test('follow-up ontbrekend mediabestand krijgt fallback-verdict zonder recovery-lus', function () {
    $intake = makeP2FollowUpIntake([
        ['type' => FollowUpItemType::Photo, 'prompt' => 'Maak een foto van de meterkast.'],
    ]);
    $item = $intake->followUpRounds()->with('items')->firstOrFail()->items->firstOrFail();

    $upload = app(StoreFollowUpUpload::class)->handle($intake, $item, p2BrightUpload());

    Storage::disk((string) $upload->disk)->delete((string) $upload->path);
    expect(Storage::disk((string) $upload->disk)->exists((string) $upload->path))->toBeFalse()
        ->and($upload->fresh()->usability_verdict)->toBeNull();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('followUpMode', true)
        ->assertSet('uploadPhase', 'assessing')
        ->call('assessPendingUploads')
        ->assertSet('uploadPhase', '');

    expect($upload->fresh()->usability_verdict)->toBe(PhotoUsabilityVerdict::Ok);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('followUpMode', true)
        ->assertSet('uploadPhase', '')
        ->assertSet('pendingAssessUploadIds', []);
});

test('recovery slaat installer_evidence uploads over', function () {
    $intake = makeP2ProgressIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $disk = (string) config('filesystems.media', 'local');
    Storage::disk($disk)->put('evidence/installer.jpg', 'x');

    IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'installer_evidence',
        'section_instance_key' => null,
        'disk' => $disk,
        'path' => 'evidence/installer.jpg',
        'original_filename' => 'installer.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1,
        'sort_order' => 1,
        'usability_verdict' => null,
    ]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('uploadPhase', '')
        ->assertSet('pendingAssessUploadIds', []);

    expect(
        IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', 'installer_evidence')
            ->whereNull('usability_verdict')
            ->exists()
    )->toBeTrue();
});

test('progressExtraNote verdwijnt bij next na foto-analyse', function () {
    $intake = makeP2ProgressIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('progressExtraNote', 'Na je foto hebben we nog één vraag: test')
        ->call('next')
        ->assertSet('progressExtraNote', '');
});

test('wizard progress volgt Vraag X van Y, daalt niet mid-session, 100% pas na afronden', function () {
    $intake = makeP2ProgressIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $intake->refresh();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    $total = count($steps);

    expect($total)->toBeGreaterThanOrEqual(10);

    // "Vraag 16 van N" ≈ stepIndex 15 → done = 15 (passed/skipped), current nog open.
    $targetIndex = min(15, max(1, $total - 2));
    $component->set('stepIndex', $targetIndex);

    $percentMid = (int) $component->viewData('progressPercent');
    $expectedMid = min(99, (int) round(($targetIndex / $total) * 100));

    expect($percentMid)->toBe($expectedMid)
        ->and($percentMid)->toBeLessThan(100)
        ->and($percentMid)->toBeGreaterThan(0);

    // Jumping back lowers raw done/total; session high-water must not decrease.
    $component->set('stepIndex', max(0, (int) floor($targetIndex / 3)));

    expect((int) $component->viewData('progressPercent'))->toBe($percentMid)
        ->and((int) $component->get('progressHighWater'))->toBe($percentMid);

    // Recalculated longer step list (extra room) must not lower the shown %.
    $component->set('form.indoor_unit_count', ['number' => 2]);

    $grownTotal = count($component->viewData('steps'));
    expect($grownTotal)->toBeGreaterThan($total)
        ->and((int) $component->viewData('progressPercent'))->toBeGreaterThanOrEqual($percentMid);

    // Afronden → 100%.
    fillKlanttestIntakeUntilComplete($intake->fresh());
    $finished = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $finishSteps = $finished->viewData('steps');
    $finished
        ->set('stepIndex', max(0, count($finishSteps) - 1))
        ->call('complete')
        ->assertSet('completed', true);

    expect((int) $finished->viewData('progressPercent'))->toBe(100);
});

test('follow-up wrong_subject telt niet mee voor 100% tot foto vervangen is', function () {
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
    ]);
    FakeAiClient::reset();
    FakeAiClient::alwaysReturn([
        'detected_subject' => 'outdoor_unit',
        'subject_match' => 'no',
        'evidence' => 'Foto toont een buitenunit, geen meterkast.',
    ]);

    $intake = makeP2FollowUpIntake([
        [
            'type' => FollowUpItemType::Photo,
            'prompt' => 'Voeg een duidelijke meterkastfoto toe',
            'decision_area_key' => 'power',
        ],
    ]);
    $item = $intake->followUpRounds()->with('items')->firstOrFail()->items->firstOrFail();

    // Zorg dat contribution task de decision_area_key power heeft.
    ContributionTask::query()
        ->where('intake_follow_up_item_id', $item->id)
        ->update(['decision_area_key' => 'power']);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('followUpMode', true)
        ->set('followUpPhotoFiles.'.$item->id, p2FixtureUpload('buitenunit-leiding.jpeg'))
        ->call('assessPendingUploads')
        ->assertSeeHtml('data-testid="follow-up-progress-percent">0%')
        ->assertSee('Nog te vervangen');

    $item->refresh()->load('uploads');
    $progress = app(FollowUpProgressCalculator::class)->calculate(collect([$item]));

    expect($progress['percent'])->toBe(0)
        ->and($progress['completed'])->toBe(0)
        ->and($progress['item_statuses'][$item->id]['status'])->toBe('mismatch');

    FakeAiClient::reset();
});
