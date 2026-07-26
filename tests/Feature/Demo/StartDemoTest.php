<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\SummarizeIntakeJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoInstallerSeeder;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
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

it('reuses one demo company across repeated demo starts', function () {
    config([
        'intake.demo.enabled' => true,
        'intake.demo.user_email' => 'demo@intake-engine.test',
    ]);

    $this->post(route('demo.start'))->assertRedirect();
    $this->post(route('demo.start'))->assertRedirect();

    $demoUser = User::query()->where('email', 'demo@intake-engine.test')->firstOrFail();

    expect(Company::query()->where('slug', 'publieke-demo-installateur')->count())->toBe(1)
        ->and(Company::query()->count())->toBe(1)
        ->and($demoUser->company?->slug)->toBe('publieke-demo-installateur');
});

it('sets a configured demo installer password when starting a demo', function () {
    config([
        'intake.demo.enabled' => true,
        'intake.demo.user_email' => 'demo@intake-engine.test',
        'intake.demo.installer_password' => 'secret-demo-password',
    ]);

    $this->post(route('demo.start'));

    $demoUser = User::query()->where('email', 'demo@intake-engine.test')->firstOrFail();

    expect(Hash::check('secret-demo-password', $demoUser->password))->toBeTrue();

    $this->post(route('login'), [
        'email' => 'demo@intake-engine.test',
        'password' => 'secret-demo-password',
    ])->assertRedirect(route('dashboard', absolute: false));
});

it('seeds the configured demo installer login', function () {
    config([
        'intake.demo.enabled' => true,
        'intake.demo.user_email' => 'demo@intake-engine.test',
        'intake.demo.installer_password' => 'secret-demo-password',
    ]);

    $this->seed(DemoInstallerSeeder::class);

    $demoUser = User::query()->where('email', 'demo@intake-engine.test')->firstOrFail();

    expect($demoUser->name)->toBe('Demo Installateur')
        ->and($demoUser->email_verified_at)->not->toBeNull()
        ->and(Hash::check('secret-demo-password', $demoUser->password))->toBeTrue();
});

it('does not seed a demo installer login without a configured password', function () {
    config([
        'intake.demo.enabled' => true,
        'intake.demo.user_email' => 'demo@intake-engine.test',
        'intake.demo.installer_password' => null,
    ]);

    $this->seed(DemoInstallerSeeder::class);

    expect(User::query()->where('email', 'demo@intake-engine.test')->exists())->toBeFalse();
});

it('shows the start demo button on the homepage when enabled', function () {
    config(['intake.demo.enabled' => true]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Start demo', false)
        ->assertSee('Demo van de klantflow', false)
        ->assertSee('In de demo uitgeschakeld', false);
});

it('hides the start demo button for authenticated users', function () {
    config(['intake.demo.enabled' => true]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertDontSee('Start demo', false)
        ->assertSee('Open dashboard', false)
        ->assertDontSee('geen account nodig', false);
});

it('explains which full-app steps are disabled during a demo intake', function () {
    config([
        'intake.demo.enabled' => true,
        'intake.demo.user_email' => 'demo@intake-engine.test',
    ]);

    $this->post(route('demo.start'));
    $intake = Intake::query()->where('is_demo', true)->firstOrFail();

    $this->get(route('customer.intake.show', ['token' => $intake->access_token]))
        ->assertOk()
        ->assertSee('Demo — je ervaart de klantflow', false)
        ->assertSee('Wel aan in deze demo', false)
        ->assertSee('AI-samenvatting en voorgestelde aandachtspunten op het dossier', false)
        ->assertSee('In de volledige app gebeurt daarna ook (hier uitgeschakeld)', false)
        ->assertSee('PDF-export van het rapport', false)
        ->assertDontSee('AI-samenvatting en aandachtspunten op het dossier', false)
        ->assertSee('Beoordeling en aanvullingsronde in het installateursdashboard', false);
});

it('lists disabled full-app steps on the demo thank-you notice', function () {
    $html = Blade::render('<x-demo-scope-notice variant="complete" />');

    expect($html)
        ->toContain('Wat je net hebt gedaan')
        ->toContain('AI-voorstel voor het dossier')
        ->toContain('Wat de volledige app daarna nog doet')
        ->toContain('E-mail met de persoonlijke klantlink')
        ->not->toContain('AI-samenvatting en aandachtspunten op het dossier')
        ->toContain('Maak een account')
        ->toContain(route('register'));
});

it('enables demo by default when DEMO_ENABLED is unset', function () {
    expect(config('intake.demo.enabled'))->toBeTrue();

    $this->get('/')
        ->assertOk()
        ->assertSee('Start demo', false);
});

it('hides the start demo button when demo mode is disabled', function () {
    config(['intake.demo.enabled' => false]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Start demo', false);
});

it('hides demo intakes from a regular installer dashboard', function () {
    config(['intake.demo.enabled' => true]);

    $this->post(route('demo.start'));

    $demoIntake = Intake::query()->where('is_demo', true)->firstOrFail();
    $regularInstaller = User::factory()->create();

    $this->actingAs($regularInstaller)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee($demoIntake->customer_name)
        ->assertDontSee($demoIntake->customer_email);
});

it('shows the demo installers own demo intakes on the dashboard', function () {
    config([
        'intake.demo.enabled' => true,
        'intake.demo.user_email' => 'demo@intake-engine.test',
        'intake.demo.installer_password' => 'secret-demo-password',
    ]);

    $this->post(route('demo.start'));

    $demoUser = User::query()->where('email', 'demo@intake-engine.test')->firstOrFail();
    $demoIntake = Intake::query()->where('is_demo', true)->firstOrFail();

    $demoIntake->forceFill([
        'status' => IntakeStatus::Completed,
        'completed_at' => now(),
        'progress_percent' => 100,
    ])->save();

    $this->actingAs($demoUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Digitale demo-opnames')
        ->assertSee('Demo-overzicht')
        ->assertSee($demoIntake->customer_name)
        ->assertSee($demoIntake->customer_email)
        ->assertSee('Demo')
        ->assertSee('Openen');
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

it('runs AI summary inline when a demo intake is completed', function () {
    Queue::fake();
    config(['ai.provider' => 'null']);

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

    $completed = app(CompleteIntake::class)->handle($intake->fresh());

    Queue::assertNotPushed(SummarizeIntakeJob::class);

    $completed->load('report');
    $aiSummary = $completed->report?->meta['ai_summary'] ?? null;

    expect($aiSummary)->toBeArray()
        ->and($aiSummary['summary'] ?? null)->toBeString()->not->toBeEmpty();
});

it('keeps demo completion successful when attention context fails before a run exists', function () {
    Queue::fake();
    config([
        'ai.provider' => 'fake',
        'ai.attention_points_prompt' => 'missing-attention-prompt',
    ]);

    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'is_demo' => true,
    ]);

    fillDemoIntakeUntilComplete($intake);

    $completed = app(CompleteIntake::class)->handle($intake->fresh());

    expect($completed->status)->toBe(IntakeStatus::Completed)
        ->and($completed->fresh()->report)->not->toBeNull();
});

it('falls back to heuristic AI when the configured demo provider fails', function () {
    Queue::fake();
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.budget.daily_cents' => null,
        'ai.budget.monthly_cents' => null,
    ]);

    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'is_demo' => true,
        'customer_name' => 'Demo AI Fallback',
        'customer_email' => 'demo-ai-fallback@demo.invalid',
        'address_line' => 'Voorbeeldstraat 1',
        'address_city' => 'Amsterdam',
    ]);

    fillDemoIntakeUntilComplete($intake);

    $completed = app(CompleteIntake::class)->handle($intake->fresh());

    Queue::assertNotPushed(SummarizeIntakeJob::class);

    $completed->load('report');
    $meta = $completed->report?->meta ?? [];

    expect($meta['ai_summary'] ?? null)->toBeArray()
        ->and($meta['ai_provider'] ?? null)->toBe('heuristic');

    expect(AiRun::query()
        ->where('intake_id', $completed->id)
        ->where('type', AiRunType::Summary)
        ->where('provider', 'openai')
        ->where('status', AiRunStatus::Failed)
        ->exists())->toBeTrue();

    expect(AiRun::query()
        ->where('intake_id', $completed->id)
        ->where('type', AiRunType::Summary)
        ->where('provider', 'heuristic')
        ->where('status', AiRunStatus::Succeeded)
        ->exists())->toBeTrue();
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
