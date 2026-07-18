<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\SummarizeIntakeJob;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

it('returns 404 when demo mode is disabled', function () {
    config(['intake.demo.enabled' => false]);

    $this->post(route('demo.start'))
        ->assertNotFound();
});

it('starts a demo intake and redirects to the customer link', function () {
    config([
        'intake.demo.enabled' => true,
        'intake.demo.user_email' => 'demo@intake-engine.test',
        'intake.demo.ttl_hours' => 12,
    ]);

    $response = $this->post(route('demo.start'));

    $intake = Intake::query()->where('is_demo', true)->first();
    expect($intake)->not->toBeNull();
    expect($intake->customer_email)->toEndWith('@demo.invalid');
    expect($intake->status)->toBe(IntakeStatus::Sent);
    expect($intake->token_expires_at)->not->toBeNull();
    expect($intake->token_expires_at->lessThanOrEqualTo(now()->addHours(12)->addMinute()))->toBeTrue();
    expect($intake->token_expires_at->greaterThan(now()->addHours(11)))->toBeTrue();

    $response->assertRedirect(route('customer.intake.show', ['token' => $intake->access_token]));

    expect(User::query()->where('email', 'demo@intake-engine.test')->exists())->toBeTrue();
});

it('shows the start demo button on the homepage when enabled', function () {
    config(['intake.demo.enabled' => true]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Start demo', false)
        ->assertSee('Demo — geen echte offerte', false);
});

it('hides the start demo button when demo mode is disabled', function () {
    config(['intake.demo.enabled' => false]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Start demo', false);
});

it('hides demo intakes from the installer dashboard', function () {
    config(['intake.demo.enabled' => true]);

    $this->post(route('demo.start'));

    $demoUser = User::query()->where('email', config('intake.demo.user_email'))->firstOrFail();
    $demoIntake = Intake::query()->where('is_demo', true)->firstOrFail();

    $this->actingAs($demoUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee($demoIntake->customer_name)
        ->assertDontSee($demoIntake->customer_email);
});

it('purges expired demo intakes and keeps active ones', function () {
    config([
        'intake.demo.enabled' => true,
        'intake.demo.ttl_hours' => 12,
    ]);

    $this->post(route('demo.start'));
    $active = Intake::query()->where('is_demo', true)->latest('id')->firstOrFail();

    $this->post(route('demo.start'));
    $expired = Intake::query()->where('is_demo', true)->latest('id')->firstOrFail();
    $expired->forceFill([
        'created_at' => Carbon::now()->subHours(13),
        'token_expires_at' => Carbon::now()->subHour(),
    ])->save();

    Artisan::call('intakes:purge-demos');

    expect(Intake::query()->whereKey($active->id)->exists())->toBeTrue();
    expect(Intake::withTrashed()->whereKey($expired->id)->exists())->toBeFalse();
});

it('does not dispatch AI summary job when a demo intake is completed', function () {
    Queue::fake();

    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'is_demo' => true,
        'customer_name' => 'Demo AI Klant',
        'customer_email' => 'demo-ai@demo.invalid',
        'address_line' => 'Voorbeeldstraat 1',
        'address_city' => 'Amsterdam',
    ]);

    fillDemoIntakeUntilComplete($intake);

    app(CompleteIntake::class)->handle($intake->fresh());

    Queue::assertNotPushed(SummarizeIntakeJob::class);
});

function fillDemoIntakeUntilComplete(Intake $intake): void
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
            $question = findDemoQuestionByKey($version, $item['question_key']);

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
                sampleDemoAnswerForQuestion($question),
            );
        }
    }

    throw new RuntimeException('Could not fill demo intake to completion within attempt budget.');
}

function findDemoQuestionByKey(IntakeTemplateVersion $version, string $key): ?IntakeQuestion
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
function sampleDemoAnswerForQuestion(IntakeQuestion $question): array
{
    return match ($question->type) {
        QuestionType::ShortText, QuestionType::LongText => ['text' => 'Demo antwoord '.$question->key],
        QuestionType::Number => ['number' => 1],
        QuestionType::SingleChoice => ['value' => $question->options->first()?->value ?? 'yes'],
        QuestionType::MultiChoice => ['values' => [$question->options->first()?->value ?? 'a']],
        QuestionType::Boolean => ['bool' => true],
        QuestionType::Photo => ['upload_ids' => []],
    };
}
