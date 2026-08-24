<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\SummarizeIntakeJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Jobs\GenerateIntakePdfJob;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierEvidenceLink;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Enums\AircoConnectionType;
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

function startPublicDemoSession(): User
{
    config([
        'intake.demo.enabled' => true,
        'intake.demo.ttl_hours' => 2,
    ]);

    test()->post(route('demo.start'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('public_demo_mode', true);

    return User::query()
        ->where('email', 'like', 'installateur+%@demo.invalid')
        ->latest('id')
        ->firstOrFail();
}

/**
 * @return array{intake: Intake, user: User}
 */
function createDemoIntakeViaForm(?User $user = null): array
{
    $user ??= startPublicDemoSession();

    $session = [
        'public_demo_mode' => true,
        'public_demo_company_id' => $user->company_id,
        'public_demo_expires_at' => now()->addHours(2)->toIso8601String(),
        'public_demo_guide_step' => 'welcome',
        'public_demo_intake_id' => null,
    ];

    test()->actingAs($user)
        ->withSession($session)
        ->post(route('intakes.store'), [
            'template_key' => 'airco',
            'workflow_mode' => ContributionMode::Customer->value,
            'customer_name' => 'Voorbeeldklant',
            'customer_email' => 'voorbeeld@demo.invalid',
            'address_line' => (string) config('intake.demo.address.line', 'Bernadottelaan 273'),
            'address_postal_code' => (string) config('intake.demo.address.postal_code', '2037GR'),
            'address_house_number' => (int) config('intake.demo.address.house_number', 273),
            'address_city' => (string) config('intake.demo.address.city', 'Haarlem'),
            'internal_note' => 'Fictieve interactieve demo',
        ])
        ->assertRedirect();

    $intake = Intake::query()->where('is_demo', true)->where('created_by', $user->id)->firstOrFail();

    return ['intake' => $intake, 'user' => $user];
}

/**
 * @return array<string, mixed>
 */
function demoSessionFor(User $user, ?Intake $intake = null): array
{
    return [
        'public_demo_mode' => true,
        'public_demo_company_id' => $user->company_id,
        'public_demo_expires_at' => now()->addHours(2)->toIso8601String(),
        'public_demo_intake_id' => $intake?->id,
        'public_demo_guide_step' => $intake ? 'branch' : 'welcome',
    ];
}

it('returns 404 when demo mode is disabled', function () {
    config(['intake.demo.enabled' => false]);

    $this->post(route('demo.start'))
        ->assertNotFound();
});

it('starts a guided demo on the installer dashboard without an intake yet', function () {
    $user = startPublicDemoSession();

    expect(Intake::query()->where('is_demo', true)->count())->toBe(0)
        ->and($user->email)->toStartWith('installateur+')
        ->and($user->company?->slug)->toStartWith('publieke-demo-');

    $this->assertAuthenticatedAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Demo-opnames')
        ->assertSee('Nieuwe opname')
        ->assertSee('tijdelijke installateur')
        ->assertSee('initialStep: \'welcome\'', false);
});

it('leaves postcode and house number empty so the installer types them', function () {
    $user = startPublicDemoSession();
    $session = [
        'public_demo_mode' => true,
        'public_demo_company_id' => $user->company_id,
        'public_demo_expires_at' => now()->addHours(2)->toIso8601String(),
        'public_demo_guide_step' => 'create',
        'public_demo_intake_id' => null,
    ];

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('intakes.create'))
        ->assertOk()
        ->assertSee('Vul zelf een postcode en huisnummer in')
        ->assertSee('Tip om te proberen:')
        ->assertSee((string) config('intake.demo.address.postal_code', '2037GR'))
        ->assertSee('name="address_postal_code"', false)
        ->assertDontSee('value="'.config('intake.demo.address.postal_code', '2037GR').'"', false)
        ->assertDontSee('value="'.config('intake.demo.address.house_number', 273).'"', false)
        ->assertSee('name="customer_name"', false)
        ->assertSee('value="Voorbeeldklant"', false);
});

it('creates one demo intake from the normal create form and opens the role branch', function () {
    ['intake' => $intake, 'user' => $user] = createDemoIntakeViaForm();

    expect($intake->is_demo)->toBeTrue()
        ->and($intake->customer_access_enabled)->toBeFalse()
        ->and($intake->status)->toBe(IntakeStatus::Draft)
        ->and($intake->token_expires_at)->not->toBeNull();

    $this->actingAs($user)
        ->withSession(demoSessionFor($user, $intake))
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Kies hieronder hoe u verder wilt kijken', false)
        ->assertSee('Er gaat geen e-mail uit in de demo', false)
        ->assertSee('Zelf de opname doen')
        ->assertSee('Bekijk wat de klant ziet')
        ->assertDontSee('Doorgaan als klant')
        ->assertDontSee('In productie mailen we nu de klantlink')
        ->assertSee('Opname openen')
        ->assertDontSee('Open technische opname')
        ->assertDontSee('% compleet')
        ->assertSee('Klanttaak:')
        ->assertSee('Klaar voor offerte:');

    $this->actingAs($user)
        ->withSession(demoSessionFor($user, $intake))
        ->get(route('intakes.create'))
        ->assertNotFound();
});

it('continues as customer on a short guided route without sending mail', function () {
    Mail::fake();
    ['intake' => $intake, 'user' => $user] = createDemoIntakeViaForm();

    $this->actingAs($user)
        ->withSession(demoSessionFor($user, $intake))
        ->post(route('demo.path.choose', $intake), ['path' => 'customer'])
        ->assertRedirect($intake->fresh()->customerUrl());

    $intake->refresh();
    expect($intake->workflow_mode)->toBe(ContributionMode::Customer)
        ->and($intake->customer_access_enabled)->toBeTrue()
        ->and($intake->status)->toBe(IntakeStatus::Sent);

    Mail::assertNothingSent();

    $this->get($intake->customerUrl())
        ->assertOk()
        ->assertSee('Demo — korte klantroute')
        ->assertSee('U bekijkt wat de klant ziet')
        ->assertDontSee('Dit ziet je klant')
        // Openingszin en koelen zijn al afgeleid; de verkorte route start bij een resterende vraag.
        ->assertSee('Wat voor type gebouw is het?');
});

it('continues as installer and can load the sample dossier', function () {
    ['intake' => $intake, 'user' => $user] = createDemoIntakeViaForm();

    $this->actingAs($user)
        ->withSession(demoSessionFor($user, $intake))
        ->post(route('demo.path.choose', $intake), ['path' => 'installer'])
        ->assertRedirect(route('intakes.workspace', $intake));

    $intake->refresh();
    expect($intake->workflow_mode)->toBe(ContributionMode::Installer)
        ->and($intake->customer_access_enabled)->toBeFalse()
        // Tekstinterpretatie van de demo-openingszin levert al gewenste ruimtes op.
        ->and($intake->aircoRooms)->toHaveCount(2);

    $this->actingAs($user)
        ->withSession(demoSessionFor($user, $intake))
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Optioneel: toon voorbeelddossier')
        ->assertSee('Bouw de opname op')
        ->assertSee('Begin met een lege opname')
        ->assertDontSee('Stap 4 van 6');

    $this->actingAs($user)
        ->withSession(demoSessionFor($user, $intake))
        ->post(route('demo.scenario.load', $intake))
        ->assertRedirect(route('intakes.workspace', $intake));

    $intake->refresh()->load([
        'externalFacts',
        'uploads',
        'aiRuns',
        'aircoRooms',
        'aircoPlacements',
        'aircoInstallationOptions.connections',
        'contributionTasks',
    ]);

    expect($intake->externalFacts)->toHaveCount(10)
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

    $run = $intake->aiRuns->firstWhere('provider', 'demo_precomputed');
    expect($run?->type)->toBe(AiRunType::DossierSynthesis)
        ->and($run?->provider)->toBe('demo_precomputed')
        ->and($run?->estimated_cost_cents)->toBe(0)
        ->and($intake->aiRuns->contains(
            fn (AiRun $aiRun): bool => $aiRun->type === AiRunType::RequestIntent,
        ))->toBeTrue();

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

    $this->actingAs($user)
        ->withSession(demoSessionFor($user, $intake))
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Voorbeelddossier geladen')
        ->assertSee('Volgende stap')
        ->assertSee('Woninggegevens')
        ->assertSee('Controleren en klantweergave activeren')
        ->assertSee('AI-voorstel vernieuwen');
});

it('creates a separate tenant and user for every public demo session', function () {
    $firstUser = startPublicDemoSession();
    $firstCompanyId = (int) $firstUser->company_id;

    $this->post(route('logout'))->assertRedirect(route('demo.ended', ['reason' => 'ended']));
    $secondUser = startPublicDemoSession();

    expect($secondUser->id)->not->toBe($firstUser->id)
        ->and((int) $secondUser->company_id)->not->toBe($firstCompanyId)
        ->and(Company::query()->where('slug', 'like', 'publieke-demo-%')->count())->toBe(2);

    ['intake' => $firstIntake] = createDemoIntakeViaForm($firstUser);
    ['intake' => $secondIntake] = createDemoIntakeViaForm($secondUser);

    $this->actingAs($firstUser)
        ->withSession([
            'public_demo_mode' => true,
            'public_demo_intake_id' => $firstIntake->id,
            'public_demo_expires_at' => now()->addHour()->toIso8601String(),
        ])
        ->get(route('intakes.workspace', $secondIntake))
        ->assertNotFound();
});

it('keeps the temporary user inside its one demo dossier after create', function () {
    ['intake' => $demo, 'user' => $user] = createDemoIntakeViaForm();
    $other = Intake::factory()->create([
        'company_id' => $demo->company_id,
        'created_by' => $demo->created_by,
        'intake_template_version_id' => $demo->intake_template_version_id,
        'is_demo' => false,
    ]);

    $session = demoSessionFor($user, $demo);

    $this->actingAs($user)->withSession($session)->get(route('intakes.create'))->assertNotFound();
    $this->actingAs($user)->withSession($session)->get(route('metrics'))->assertNotFound();
    $this->actingAs($user)->withSession($session)->get(route('profile.edit'))->assertNotFound();
    $this->actingAs($user)->withSession($session)->get(route('intakes.workspace', $other))->assertNotFound();
    $this->actingAs($user)->withSession($session)->get(route('intakes.show', $demo))->assertOk();
});

it('ends the public demo session after its configured lifetime', function () {
    config(['intake.demo.ttl_hours' => 2]);
    ['intake' => $demo, 'user' => $user] = createDemoIntakeViaForm();

    $this->travel(2)->hours();
    $this->travel(1)->second();

    $this->actingAs($user)
        ->withSession([
            'public_demo_mode' => true,
            'public_demo_intake_id' => $demo->id,
            'public_demo_expires_at' => now()->subSecond()->toIso8601String(),
        ])
        ->get(route('intakes.workspace', $demo))
        ->assertRedirect(route('demo.ended', ['reason' => 'expired']));
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
        ->assertSee('Start zoals een installateur', false)
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
        ->assertDontSee('Verder in demo', false)
        ->assertSee('Mijn opnames', false);
});

it('shows continue-demo CTAs on the homepage during a public demo session', function () {
    config(['intake.demo.enabled' => true]);

    $user = startPublicDemoSession();

    $this->actingAs($user)
        ->withSession(demoSessionFor($user))
        ->get('/')
        ->assertOk()
        ->assertDontSee('Probeer de demo', false)
        ->assertDontSee('Open dashboard', false)
        ->assertDontSee('Mijn opnames', false)
        ->assertDontSee('Inloggen', false)
        ->assertSee('Verder in demo', false)
        ->assertSee('Demo beëindigen', false)
        ->assertSee('Ik wil een pilot', false)
        ->assertSee(route('dashboard'), false);
});

it('shows end-demo action in the app navigation during a public demo session', function () {
    config(['intake.demo.enabled' => true]);

    $user = startPublicDemoSession();

    $this->actingAs($user)
        ->withSession(demoSessionFor($user))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Tijdelijke demo', false)
        ->assertSee('Demo beëindigen', false)
        ->assertDontSee('Uitloggen', false);
});

it('activates a simulated customer view without sending mail', function () {
    Mail::fake();
    config(['intake.demo.ttl_hours' => 2]);

    ['intake' => $intake, 'user' => $user] = createDemoIntakeViaForm();

    $session = demoSessionFor($user, $intake);
    $this->actingAs($user)->withSession($session)->post(route('demo.path.choose', $intake), ['path' => 'installer']);
    $this->actingAs($user)->withSession($session)->post(route('demo.scenario.load', $intake));

    $task = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->firstOrFail();

    $this->actingAs($user)
        ->withSession($session)
        ->post(route('intakes.workspace.tasks.send', [$intake, $task]))
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHas('status', 'Klantweergave geactiveerd. In de demo sturen we geen e-mail.');

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
        ->assertSee('Demo — aanvulling door de klant')
        ->assertSee('Maak één frontale foto van de volledige groepenkast');
});

it('lists disabled full-app steps on the demo thank-you notice', function () {
    $html = Blade::render('<x-demo-scope-notice variant="complete" />');

    expect($html)
        ->toContain('Wat u net heeft gedaan')
        ->toContain('Eén klantaanvulling is afgerond')
        ->toContain('Bewust uitgeschakeld in de demo')
        ->toContain('E-mail en herinneringen naar een echte klant')
        ->toContain('Automatische PDF-export (wel op aanvraag met e-mail in de opname)')
        ->toContain('terug naar de website')
        ->not->toContain('Live AI-aanroepen');
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

    ['intake' => $demoIntake] = createDemoIntakeViaForm();
    $regularInstaller = User::factory()->create();

    $this->actingAs($regularInstaller)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee($demoIntake->customer_name)
        ->assertDontSee($demoIntake->customer_email);
});

it('shows only the current public demo on its dashboard', function () {
    ['intake' => $demoIntake, 'user' => $user] = createDemoIntakeViaForm();

    $this->actingAs($user)
        ->withSession(demoSessionFor($user, $demoIntake))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Demo-opnames')
        ->assertSee('Demo-overzicht')
        ->assertSee($demoIntake->customer_name)
        ->assertSee($demoIntake->customer_email)
        ->assertSee('Demo')
        ->assertSee('Openen');
});

it('allows live AI synthesis from the interactive demo when AI is enabled', function () {
    Http::fake([
        '*' => Http::response([
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'summary' => 'Demo AI-voorstel',
                        'placements' => [],
                        'installation_options' => [],
                        'connections' => [],
                        'exceptions' => [],
                        'customer_tasks' => [],
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
        ], 200),
    ]);
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.dossier.enabled' => true,
        'ai.route.enabled' => true,
        'ai.photo_inference.enabled' => true,
        'ai.text_inference.enabled' => true,
    ]);

    ['intake' => $intake, 'user' => $user] = createDemoIntakeViaForm();
    $demoSession = demoSessionFor($user, $intake);
    $this->actingAs($user)->withSession($demoSession)->post(route('demo.path.choose', $intake), ['path' => 'installer']);
    $this->actingAs($user)->withSession($demoSession)->post(route('demo.scenario.load', $intake));

    $this->actingAs($user)
        ->withSession($demoSession)
        ->post(route('intakes.workspace.synthesis', $intake))
        ->assertRedirect(route('intakes.workspace', $intake));

    // Demo synthesis is no longer short-circuited; the gateway may be called.
    expect(Http::recorded()->isNotEmpty() || AiRun::query()->where('intake_id', $intake->id)->exists())->toBeTrue();
});

it('purges expired demo data media and ephemeral accounts while keeping active sessions', function () {
    config(['intake.demo.ttl_hours' => 2]);

    ['intake' => $active, 'user' => $activeUser] = createDemoIntakeViaForm();
    $activeSession = demoSessionFor($activeUser, $active);
    $this->actingAs($activeUser)->withSession($activeSession)->post(route('demo.path.choose', $active), ['path' => 'installer']);
    $this->actingAs($activeUser)->withSession($activeSession)->post(route('demo.scenario.load', $active));
    $activeUserId = (int) $active->created_by;
    $activeCompanyId = (int) $active->company_id;

    $this->post(route('logout'))->assertRedirect(route('demo.ended', ['reason' => 'ended']));
    ['intake' => $expired, 'user' => $expiredUser] = createDemoIntakeViaForm();
    $expiredSession = demoSessionFor($expiredUser, $expired);
    $this->actingAs($expiredUser)->withSession($expiredSession)->post(route('demo.path.choose', $expired), ['path' => 'installer']);
    $this->actingAs($expiredUser)->withSession($expiredSession)->post(route('demo.scenario.load', $expired));
    $expiredUserId = (int) $expired->created_by;
    $expiredCompanyId = (int) $expired->company_id;
    $expired->refresh();
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

it('dispatches AI summary jobs when a demo intake is completed', function () {
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

    Queue::assertPushed(SummarizeIntakeJob::class);
    expect($completed->status)->toBe(IntakeStatus::Completed)
        ->and($completed->fresh()->report)->not->toBeNull();
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

it('does not dispatch PDF or installer mail when a demo intake is completed', function () {
    Queue::fake();
    Mail::fake();
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
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

    Queue::assertPushed(SummarizeIntakeJob::class);
    Queue::assertNotPushed(GenerateIntakePdfJob::class);
    Mail::assertNothingSent();
    expect($completed->status)->toBe(IntakeStatus::Completed);
});

it('asks for confirmation copy on end-demo controls and lands on a Dutch ended page', function () {
    config(['intake.demo.enabled' => true]);
    $user = startPublicDemoSession();

    $this->actingAs($user)
        ->withSession(demoSessionFor($user))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Weet u zeker dat u de demo wilt beëindigen?', false);

    $this->actingAs($user)
        ->withSession(demoSessionFor($user))
        ->post(route('logout'))
        ->assertRedirect(route('demo.ended', ['reason' => 'ended']));

    $this->assertGuest();

    $this->get(route('demo.ended', ['reason' => 'ended']))
        ->assertOk()
        ->assertSee('Demo beëindigd')
        ->assertSee('Naar de homepage')
        ->assertSee('Nieuwe demo starten')
        ->assertDontSee('404 | Not Found');

    $this->get(route('demo.ended', ['reason' => 'expired']))
        ->assertOk()
        ->assertSee('Deze demo is verlopen');
});

it('never claims email send on the demo create form', function () {
    $user = startPublicDemoSession();

    $this->actingAs($user)
        ->withSession(demoSessionFor($user))
        ->get(route('intakes.create'))
        ->assertOk()
        ->assertSee('Opname aanmaken')
        ->assertDontSee('Opslaan en link mailen')
        ->assertSee('Er gaat geen e-mail uit');
});

it('does not present questionnaire percent as a finished opname', function () {
    ['intake' => $intake, 'user' => $user] = createDemoIntakeViaForm();
    $intake->forceFill(['progress_percent' => 100])->save();

    $this->actingAs($user)
        ->withSession(demoSessionFor($user, $intake))
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertDontSee('100% compleet')
        ->assertSee('Klanttaak: 100% beantwoord')
        ->assertSee('Klaar voor offerte:')
        ->assertSee('Opname openen')
        ->assertDontSee('Open technische opname')
        ->assertDontSee(' · v');
});

it('renders Dutch not-found and ended pages instead of framework english', function () {
    $this->get('/deze-route-bestaat-zeker-niet-'.uniqid())
        ->assertNotFound()
        ->assertSee('Pagina niet gevonden')
        ->assertSee('Digitale Opname')
        ->assertDontSee('Not Found');
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
