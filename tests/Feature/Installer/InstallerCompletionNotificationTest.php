<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Mail\InstallerIntakeCompletedMail;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    Mail::fake();
});

function makeCompletableIntakeForInstallerMail(): Intake
{
    $user = User::factory()->create([
        'email' => 'installateur.notify@example.com',
        'name' => 'Notify Installateur',
    ]);
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Afgeronde Klant',
        'customer_email' => 'afgerond@example.com',
        'address_line' => 'Meldstraat 1',
        'address_city' => 'Utrecht',
    ]);
}

function fillForInstallerMail(Intake $intake): void
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
            $question = findQuestionForInstallerMail($version, $item['question_key']);

            if ($question === null) {
                continue;
            }

            if ($question->type === QuestionType::Photo) {
                $store->handle(
                    $intake,
                    $item['question_key'],
                    $item['section_instance_key'],
                    UploadedFile::fake()->image($item['question_key'].'.jpg', 640, 480),
                );

                continue;
            }

            $save->handle(
                $intake,
                $item['question_key'],
                $item['section_instance_key'],
                sampleAnswerForInstallerMail($question),
            );
        }
    }

    throw new RuntimeException('Could not fill intake for installer mail test.');
}

function findQuestionForInstallerMail(IntakeTemplateVersion $version, string $key): ?IntakeQuestion
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
function sampleAnswerForInstallerMail(IntakeQuestion $question): array
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

test('completing an intake mails the installer and records a safe activity event', function () {
    $intake = makeCompletableIntakeForInstallerMail();
    fillForInstallerMail($intake);

    app(CompleteIntake::class)->handle($intake->fresh());

    Mail::assertSent(InstallerIntakeCompletedMail::class, function (InstallerIntakeCompletedMail $mail) use ($intake): bool {
        return $mail->hasTo('installateur.notify@example.com')
            && $mail->intake->is($intake)
            && str_contains($mail->envelope()->subject, 'Opname afgerond');
    });

    $event = IntakeActivityEvent::query()
        ->where('intake_id', $intake->id)
        ->where('event', 'installer_completion_mailed')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->actor_type)->toBe('system')
        ->and($event->properties)->toBe(['mailer' => 'array']);

    $encoded = json_encode($event->properties);
    expect($encoded)->not->toContain($intake->access_token)
        ->and($encoded)->not->toContain('installateur.notify@example.com');
});

test('installer completion mail is skipped when mailer is log', function () {
    config(['mail.default' => 'log']);

    $intake = makeCompletableIntakeForInstallerMail();
    fillForInstallerMail($intake);

    app(CompleteIntake::class)->handle($intake->fresh());

    Mail::assertNothingSent();

    expect(
        IntakeActivityEvent::query()
            ->where('intake_id', $intake->id)
            ->where('event', 'installer_completion_mailed')
            ->exists()
    )->toBeFalse();
});

test('dashboard highlights completed intakes awaiting review', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    Intake::factory()->completed()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Nieuw Afgerond',
        'customer_email' => 'nieuw@example.com',
        'reviewed_at' => null,
    ]);

    Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Nog Open',
        'customer_email' => 'open@example.com',
        'status' => IntakeStatus::Sent,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Nieuw afgerond', false)
        ->assertSee('Nieuw Afgerond')
        ->assertSee('Nog Open');
});
