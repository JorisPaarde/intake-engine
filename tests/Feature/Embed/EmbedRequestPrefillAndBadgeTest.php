<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\DeriveIntentFromRequestJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\PrepareEmbedStagingTest;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\EmbedFormToken;
use App\Domains\Intake\Support\EmbedStagingTest;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(IntakeTemplateSeeder::class);
    config([
        'ai.request_prefill.sync_on_create' => false,
        'ai.text_inference.enabled' => false,
        'ai.provider' => 'fake',
    ]);
});

/**
 * @return array{company: Company, user: User, key: string}
 */
function embedPrefillCompany(): array
{
    $company = Company::factory()->withEmbedKey('embed-prefill-key-bbbbbbbbbbbb')->create([
        'embed_enabled' => true,
        'embed_allowed_origins' => ['www.example.nl'],
        'embed_daily_limit' => 20,
        'name' => 'Prefill Koeling',
    ]);
    $user = User::factory()->for($company)->create();

    return [
        'company' => $company->fresh(),
        'user' => $user,
        'key' => (string) $company->embed_public_key,
    ];
}

/**
 * @return array<string, mixed>
 */
function embedPrefillPayload(string $key, array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Embed Prefill Klant',
        'customer_email' => 'embed.prefill@example.com',
        'customer_phone' => '0611111111',
        'address_postal_code' => '1234AB',
        'address_house_number' => 12,
        'address_house_number_addition' => null,
        'privacy_consent' => '1',
        'website' => '',
        EmbedFormToken::FIELD => app(EmbedFormToken::class)->issue($key),
    ], $overrides);
}

function postEmbedPrefill(string $key, array $payload = [])
{
    return test()->withHeaders([
        'Origin' => 'https://www.example.nl',
        'Referer' => 'https://www.example.nl/airco',
    ])->post(route('embed.store', ['public_key' => $key]), embedPrefillPayload($key, $payload));
}

test('embed create has no ai_runs and mount does not dispatch request-prefill', function () {
    Mail::fake();
    Queue::fake();
    $ctx = embedPrefillCompany();

    postEmbedPrefill($ctx['key'])->assertStatus(303);

    $intake = Intake::query()->where('customer_email', 'embed.prefill@example.com')->firstOrFail();

    expect($intake->source)->toBe(IntakeSource::Embed)
        ->and(AiRun::query()->where('intake_id', $intake->id)->count())->toBe(0);

    Queue::assertNotPushed(DeriveIntentFromRequestJob::class);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('waitingForPrefill', false);

    Queue::assertNotPushed(DeriveIntentFromRequestJob::class);
    expect(AiRun::query()->where('intake_id', $intake->id)->count())->toBe(0);
});

test('first customer step on embed intake dispatches request-prefill exactly once', function () {
    Mail::fake();
    Queue::fake();
    $ctx = embedPrefillCompany();

    postEmbedPrefill($ctx['key'])->assertStatus(303);
    $intake = Intake::query()->where('customer_email', 'embed.prefill@example.com')->firstOrFail();
    expect(AiRun::query()->where('intake_id', $intake->id)->count())->toBe(0);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);

    Queue::assertNotPushed(DeriveIntentFromRequestJob::class);

    $component->set('form.ownership.value', 'owned');

    Queue::assertPushed(DeriveIntentFromRequestJob::class, 1);
    Queue::assertPushed(
        DeriveIntentFromRequestJob::class,
        static fn (DeriveIntentFromRequestJob $job): bool => $job->intakeId === $intake->id
            && $job->allowExternal === true
            && $job->skipIfCustomerStarted === false,
    );

    expect(AiRun::query()->where('intake_id', $intake->id)->count())->toBeGreaterThanOrEqual(1);

    // Tweede antwoord / refresh-simulatie: geen tweede dispatch.
    $component->set('form.noise_sensitive.bool', true);
    Queue::assertPushed(DeriveIntentFromRequestJob::class, 1);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.ownership.value', 'rental');

    Queue::assertPushed(DeriveIntentFromRequestJob::class, 1);
});

test('next on embed intake also triggers a single request-prefill dispatch', function () {
    Mail::fake();
    Queue::fake();
    $ctx = embedPrefillCompany();

    postEmbedPrefill($ctx['key'], [
        'customer_email' => 'embed.next@example.com',
    ])->assertStatus(303);

    $intake = Intake::query()->where('customer_email', 'embed.next@example.com')->firstOrFail();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('next');

    Queue::assertPushed(DeriveIntentFromRequestJob::class, 1);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('next');

    Queue::assertPushed(DeriveIntentFromRequestJob::class, 1);
});

test('installer intakes do not get embed prefill dispatch from the wizard', function () {
    Queue::fake();
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'source' => IntakeSource::Installer,
        'customer_access_enabled' => true,
        'status' => IntakeStatus::Sent,
    ]);

    // Simuleer create-prefill die al heeft gedraaid.
    DeriveIntentFromRequestJob::markPending($intake);
    Queue::fake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.ownership.value', 'owned');

    Queue::assertNotPushed(DeriveIntentFromRequestJob::class);
});

test('dashboard and detail show Nieuw via website badge for embed intakes', function () {
    $ctx = embedPrefillCompany();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $embedIntake = Intake::factory()->create([
        'created_by' => $ctx['user']->id,
        'company_id' => $ctx['company']->id,
        'intake_template_version_id' => $version->id,
        'source' => IntakeSource::Embed,
        'customer_name' => 'Website Klant',
        'customer_email' => 'website.badge@example.com',
    ]);

    $installerIntake = Intake::factory()->create([
        'created_by' => $ctx['user']->id,
        'company_id' => $ctx['company']->id,
        'intake_template_version_id' => $version->id,
        'source' => IntakeSource::Installer,
        'customer_name' => 'Gewone Klant',
        'customer_email' => 'gewoon.badge@example.com',
    ]);

    $dashboard = $this->actingAs($ctx['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Nieuw via website', false)
        ->assertSee('data-testid="embed-source-badge"', false)
        ->getContent();

    expect(substr_count($dashboard, 'Nieuw via website'))->toBeGreaterThanOrEqual(1)
        ->and($dashboard)->toContain('Website Klant')
        ->and($dashboard)->toContain('Gewone Klant');

    $this->actingAs($ctx['user'])
        ->get(route('intakes.show', $embedIntake))
        ->assertOk()
        ->assertSee('Nieuw via website', false)
        ->assertSee('data-testid="embed-source-badge"', false);

    $this->actingAs($ctx['user'])
        ->get(route('intakes.show', $installerIntake))
        ->assertOk()
        ->assertDontSee('data-testid="embed-source-badge"', false);
});

test('embed staging test page works after prepare command and is blocked in production', function () {
    $this->app->detectEnvironment(static fn (): string => 'testing');

    $this->get(route('embed.staging-test'))->assertNotFound();

    $this->artisan('embed:prepare-staging-test')
        ->assertSuccessful()
        ->expectsOutputToContain('/embed-test');

    $company = Company::query()->where('embed_public_key', EmbedStagingTest::PUBLIC_KEY)->first();
    expect($company)->not->toBeNull()
        ->and($company->embed_enabled)->toBeTrue()
        ->and(User::query()->where('email', EmbedStagingTest::USER_EMAIL)->exists())->toBeTrue();

    $this->get(route('embed.staging-test'))
        ->assertOk()
        ->assertSee('Embed staging-test', false)
        ->assertSee(EmbedStagingTest::PUBLIC_KEY, false)
        ->assertSee('data-testid="embed-staging-test-frame"', false)
        ->assertSee('/embed/'.EmbedStagingTest::PUBLIC_KEY, false);

    // Idempotent prepare: tweede artisan-run + action mogen geen dubbele company/user maken.
    $this->artisan('embed:prepare-staging-test', ['--host' => 'staging.intake-engine.nl'])
        ->assertSuccessful();
    app(PrepareEmbedStagingTest::class)->handle('staging.intake-engine.nl');

    expect(Company::query()->where('embed_public_key', EmbedStagingTest::PUBLIC_KEY)->count())->toBe(1)
        ->and(User::query()->where('email', EmbedStagingTest::USER_EMAIL)->count())->toBe(1);

    $company = Company::query()->where('embed_public_key', EmbedStagingTest::PUBLIC_KEY)->firstOrFail();
    expect($company->embedAllowedOrigins())->toContain('staging.intake-engine.nl');

    $this->app->detectEnvironment(static fn (): string => 'production');
    $this->get(route('embed.staging-test'))->assertNotFound();
    $this->artisan('embed:prepare-staging-test')->assertFailed();
});
