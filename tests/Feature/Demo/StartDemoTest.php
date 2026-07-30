<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\SummarizeIntakeJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierEvidenceLink;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Enums\AircoConnectionType;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

it('opens an isolated prefilled installer workspace', function () {
    config([
        'intake.demo.enabled' => true,
        'intake.demo.ttl_hours' => 2,
    ]);

    $response = $this->post(route('demo.start'));

    $intake = Intake::query()
        ->with([
            'creator.company',
            'externalFacts',
            'uploads',
            'aiRuns',
            'aircoRooms',
            'aircoPlacements',
            'aircoInstallationOptions.connections',
            'contributionTasks',
        ])
        ->where('is_demo', true)
        ->firstOrFail();
    $creator = $intake->creator;

    expect($creator)->not->toBeNull()
        ->and($creator?->email)->toStartWith('installateur+')
        ->and($creator?->email)->toEndWith('@demo.invalid')
        ->and($creator?->company?->slug)->toStartWith('publieke-demo-')
        ->and($intake->customer_email)->toEndWith('@demo.invalid')
        ->and($intake->workflow_mode)->toBe(ContributionMode::Installer)
        ->and($intake->status)->toBe(IntakeStatus::InProgress)
        ->and($intake->customer_access_enabled)->toBeFalse()
        ->and($intake->token_expires_at)->not->toBeNull()
        ->and($intake->token_expires_at?->lessThanOrEqualTo(now()->addHours(2)->addMinute()))->toBeTrue()
        ->and($intake->token_expires_at?->greaterThan(now()->addHour()))->toBeTrue()
        ->and($intake->externalFacts)->toHaveCount(10)
        ->and($intake->aircoRooms)->toHaveCount(2)
        ->and($intake->aircoPlacements)->toHaveCount(5)
        ->and($intake->aircoInstallationOptions)->toHaveCount(1)
        ->and($intake->aircoInstallationOptions->first()?->connections)->toHaveCount(5)
        ->and($intake->uploads)->toHaveCount(4)
        ->and($intake->contributionTasks->where('status', ContributionTaskStatus::Proposed))->toHaveCount(1);

    expect($intake->aircoInstallationOptions->first()?->connections
        ->groupBy(fn ($connection) => $connection->type->value)
        ->map->count()
        ->all())->toBe([
            AircoConnectionType::Refrigerant->value => 2,
            AircoConnectionType::Condensate->value => 2,
            AircoConnectionType::Power->value => 1,
        ]);

    $run = $intake->aiRuns->first();
    expect($run?->type)->toBe(AiRunType::DossierSynthesis)
        ->and($run?->provider)->toBe('demo_precomputed')
        ->and($run?->estimated_cost_cents)->toBe(0);

    foreach ($intake->uploads as $upload) {
        Storage::disk($upload->disk)->assertExists($upload->path);
        expect($upload->analysis_path)->not->toBeNull();
        Storage::disk($upload->disk)->assertExists((string) $upload->analysis_path);

        preg_match('/^subject-(\d+)$/', (string) $upload->section_instance_key, $matches);
        expect($matches[1] ?? null)->not->toBeNull();
        expect(DossierEvidenceLink::query()
            ->where('dossier_subject_id', (int) $matches[1])
            ->where('evidence_type', 'intake_upload')
            ->where('evidence_id', $upload->id)
            ->exists())->toBeTrue();
    }

    $response
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHas('public_demo_intake_id', $intake->id);
    $this->assertAuthenticatedAs($creator);

    $this->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Interactieve demo · echte werkplek')
        ->assertSee('Bekende woningcontext')
        ->assertSee('Beeldbewijs in het dossier')
        ->assertSee('Optie A · één multi-split')
        ->assertSee('Koelleiding slaapkamer ouders')
        ->assertSee('Condensafvoer werkkamer')
        ->assertSee('Voeding naar buitenunit')
        ->assertSee('Groepsaanduiding deels onleesbaar')
        ->assertSee('Controleren en klantweergave activeren')
        ->assertSee('Vooraf berekend · € 0')
        ->assertDontSee('AI-voorstel vernieuwen');
});

it('creates a separate tenant and user for every public demo session', function () {
    $this->post(route('demo.start'))->assertRedirect();
    $first = Intake::query()->where('is_demo', true)->firstOrFail();
    $firstUserId = (int) $first->created_by;
    $firstCompanyId = (int) $first->company_id;

    $this->post(route('logout'))->assertRedirect('/');
    $this->post(route('demo.start'))->assertRedirect();
    $second = Intake::query()
        ->where('is_demo', true)
        ->where('id', '!=', $first->id)
        ->firstOrFail();

    expect($second->created_by)->not->toBe($firstUserId)
        ->and($second->company_id)->not->toBe($firstCompanyId)
        ->and(Company::query()->where('slug', 'like', 'publieke-demo-%')->count())->toBe(2);

    $firstUser = User::query()->findOrFail($firstUserId);
    $this->actingAs($firstUser)
        ->withSession(['public_demo_intake_id' => $first->id])
        ->get(route('intakes.workspace', $second))
        ->assertNotFound();
});

it('keeps the temporary user inside its one demo dossier', function () {
    $this->post(route('demo.start'))->assertRedirect();
    $demo = Intake::query()->where('is_demo', true)->firstOrFail();
    $other = Intake::factory()->create([
        'company_id' => $demo->company_id,
        'created_by' => $demo->created_by,
        'intake_template_version_id' => $demo->intake_template_version_id,
        'is_demo' => false,
    ]);

    $this->get(route('intakes.create'))->assertNotFound();
    $this->get(route('metrics'))->assertNotFound();
    $this->get(route('profile.edit'))->assertNotFound();
    $this->get(route('intakes.workspace', $other))->assertNotFound();
    $this->get(route('intakes.workspace', $demo))->assertOk();
});

it('ends the public demo session after its configured lifetime', function () {
    config(['intake.demo.ttl_hours' => 2]);

    $this->post(route('demo.start'))->assertRedirect();
    $demo = Intake::query()->where('is_demo', true)->firstOrFail();

    $this->travel(2)->hours();
    $this->travel(1)->second();

    $this->get(route('intakes.workspace', $demo))
        ->assertRedirect('/');
    $this->assertGuest();
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
        ->assertSee('Probeer de demo', false)
        ->assertSee('Geen voorbezoek voor één ontbrekende foto.', false)
        ->assertSee('Voor jou', false)
        ->assertSee('Voor je klant', false)
        ->assertSee('Fictieve voorbeeldopname.', false)
        ->assertSee('Ik wil een pilot proberen', false);
});

it('hides the start demo button for authenticated users', function () {
    config(['intake.demo.enabled' => true]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertDontSee('Probeer de demo', false)
        ->assertSee('Open dashboard', false)
        ->assertDontSee('Geen account nodig', false);
});

it('activates a simulated customer view without sending mail', function () {
    Mail::fake();
    config(['intake.demo.ttl_hours' => 2]);

    $this->post(route('demo.start'))->assertRedirect();
    $intake = Intake::query()->where('is_demo', true)->firstOrFail();
    $task = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->firstOrFail();

    $this->post(route('intakes.workspace.tasks.send', [$intake, $task]))
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHas('status', 'Klantweergave geactiveerd. In de demo wordt geen e-mail verstuurd.');

    $intake->refresh();
    expect($intake->workflow_mode)->toBe(ContributionMode::Hybrid)
        ->and($intake->status)->toBe(IntakeStatus::AwaitingCustomer)
        ->and($intake->customer_access_enabled)->toBeTrue()
        ->and($intake->token_expires_at?->lessThanOrEqualTo(now()->addHours(2)->addMinute()))->toBeTrue()
        ->and($task->fresh()?->status)->toBe(ContributionTaskStatus::Cancelled)
        ->and($intake->contributionTasks()->where('status', ContributionTaskStatus::Open)->count())->toBe(1);

    Mail::assertNothingSent();

    $this->get($intake->customerUrl())
        ->assertOk()
        ->assertSee('Demo — gerichte klantaanvulling')
        ->assertSee('Maak één frontale foto van de volledige groepenkast');
});

it('lists disabled full-app steps on the demo thank-you notice', function () {
    $html = Blade::render('<x-demo-scope-notice variant="complete" />');

    expect($html)
        ->toContain('Wat je net hebt gedaan')
        ->toContain('Eén gerichte klantaanvulling afgerond')
        ->toContain('Bewust uitgeschakeld in de demo')
        ->toContain('E-mail en herinneringen naar een echte klant')
        ->toContain('terug naar de website');
});

it('enables demo by default when DEMO_ENABLED is unset', function () {
    expect(config('intake.demo.enabled'))->toBeTrue();

    $this->get('/')
        ->assertOk()
        ->assertSee('Probeer de demo', false);
});

it('hides the start demo button when demo mode is disabled', function () {
    config(['intake.demo.enabled' => false]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Probeer de demo', false);
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

it('shows only the current public demo on its dashboard', function () {
    $this->post(route('demo.start'))->assertRedirect();
    $demoIntake = Intake::query()->where('is_demo', true)->firstOrFail();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Digitale demo-opnames')
        ->assertSee('Demo-overzicht')
        ->assertSee($demoIntake->customer_name)
        ->assertSee($demoIntake->customer_email)
        ->assertSee('Demo')
        ->assertSee('Open werkplek');
});

it('never invokes external AI from the interactive demo', function () {
    Http::fake();
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.dossier.enabled' => true,
        'ai.route.enabled' => true,
        'ai.photo_inference.enabled' => true,
        'ai.text_inference.enabled' => true,
    ]);

    $this->post(route('demo.start'))->assertRedirect();
    $intake = Intake::query()->where('is_demo', true)->firstOrFail();
    $session = PipeRouteSession::query()->where('intake_id', $intake->id)->firstOrFail();

    $this->post(route('intakes.workspace.synthesis', $intake))
        ->assertSessionHas('status', 'Het AI-voorstel is vooraf berekend; de demo gebruikt geen live AI.');
    $this->post(route('intakes.workspace.routes.synthesize', [$intake, $session]))
        ->assertSessionHas('status', 'Deze route is vooraf berekend; de demo gebruikt geen live AI.');

    Http::assertNothingSent();
    expect(AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('provider', 'openai')
        ->exists())->toBeFalse();
});

it('purges expired demo data media and ephemeral accounts while keeping active sessions', function () {
    config(['intake.demo.ttl_hours' => 2]);

    $this->post(route('demo.start'))->assertRedirect();
    $active = Intake::query()->where('is_demo', true)->latest('id')->firstOrFail();
    $activeUserId = (int) $active->created_by;
    $activeCompanyId = (int) $active->company_id;

    $this->post(route('logout'))->assertRedirect('/');
    $this->post(route('demo.start'))->assertRedirect();
    $expired = Intake::query()->where('is_demo', true)->latest('id')->firstOrFail();
    $expiredUserId = (int) $expired->created_by;
    $expiredCompanyId = (int) $expired->company_id;
    $expiredFiles = $expired->uploads()->get()->flatMap(
        static fn ($upload): array => array_values(array_filter([$upload->path, $upload->analysis_path])),
    )->all();
    $expiredAerial = $expired->externalFacts()->where('fact_key', 'aerial_image')->firstOrFail();
    $expiredFiles[] = $expiredAerial->value['media_path'];
    $expired->forceFill([
        'created_at' => Carbon::now()->subHours(3),
        'token_expires_at' => Carbon::now()->subHour(),
    ])->save();

    Artisan::call('intakes:purge-demos');

    expect(Intake::query()->whereKey($active->id)->exists())->toBeTrue();
    expect(Intake::withTrashed()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($expiredUserId)->exists())->toBeFalse()
        ->and(Company::query()->whereKey($expiredCompanyId)->exists())->toBeFalse()
        ->and(User::query()->whereKey($activeUserId)->exists())->toBeTrue()
        ->and(Company::query()->whereKey($activeCompanyId)->exists())->toBeTrue();

    foreach ($expiredFiles as $path) {
        Storage::disk((string) config('filesystems.media', 'local'))->assertMissing((string) $path);
    }
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

it('uses only local heuristic AI when an old customer demo is completed', function () {
    Queue::fake();
    Http::fake();
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
        ->exists())->toBeFalse();

    expect(AiRun::query()
        ->where('intake_id', $completed->id)
        ->where('type', AiRunType::Summary)
        ->where('provider', 'heuristic')
        ->where('status', AiRunStatus::Succeeded)
        ->exists())->toBeTrue();

    Http::assertNothingSent();
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
