<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\DeriveIntentFromRequestJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Mail\CustomerIntakeLinkMail;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(IntakeTemplateSeeder::class);
});

/**
 * @return array{company: Company, older: User, newer: User, key: string}
 */
function embedCompanyReady(array $companyOverrides = []): array
{
    $company = Company::factory()->withEmbedKey('embed-test-key-aaaaaaaaaaaa')->create(array_merge([
        'embed_enabled' => true,
        'embed_allowed_origins' => ['www.example.nl'],
        'embed_daily_limit' => 20,
        'name' => 'Voorbeeld Koeling',
    ], $companyOverrides));

    $older = User::factory()->for($company)->create(['created_at' => now()->subDay()]);
    $newer = User::factory()->for($company)->create(['created_at' => now()]);

    return [
        'company' => $company->fresh(),
        'older' => $older,
        'newer' => $newer,
        'key' => (string) $company->embed_public_key,
    ];
}

/**
 * @return array<string, mixed>
 */
function embedPayload(array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Sara Klant',
        'customer_email' => 'sara.klant@example.com',
        'customer_phone' => '0612345678',
        'address_postal_code' => '1234AB',
        'address_house_number' => 10,
        'address_house_number_addition' => null,
        'privacy_consent' => '1',
        'website' => '',
    ], $overrides);
}

function postEmbed(string $key, array $payload = [], array $headers = [])
{
    $defaultHeaders = [
        'Origin' => 'https://www.example.nl',
        'Referer' => 'https://www.example.nl/airco',
    ];

    return test()->withHeaders(array_merge($defaultHeaders, $headers))
        ->from(route('embed.show', ['public_key' => $key]))
        ->post(route('embed.store', ['public_key' => $key]), embedPayload($payload));
}

test('embed form shows for enabled key with frame-ancestors header', function () {
    $ctx = embedCompanyReady();

    $response = $this->get(route('embed.show', ['public_key' => $ctx['key']]));

    $response->assertOk()
        ->assertSee('Start je opname', false)
        ->assertSee('Voorbeeld Koeling', false)
        ->assertSee('verwerkingsverantwoordelijke', false)
        ->assertSee('name="website"', false)
        ->assertSee('target="_top"', false)
        ->assertHeader('Content-Security-Policy');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'self' https://www.example.nl")
        ->and($csp)->not->toContain('*');
});

test('unknown or disabled embed key returns 404', function () {
    $this->get(route('embed.show', ['public_key' => 'does-not-exist-key']))->assertNotFound();

    $disabled = embedCompanyReady(['embed_enabled' => false]);
    $this->get(route('embed.show', ['public_key' => $disabled['key']]))->assertNotFound();
    postEmbed($disabled['key'])->assertNotFound();
});

test('happy path creates embed intake without AI and redirects to customer link', function () {
    Mail::fake();
    Queue::fake();
    Bus::fake();

    $ctx = embedCompanyReady();

    $response = postEmbed($ctx['key']);

    $intake = Intake::query()->where('customer_email', 'sara.klant@example.com')->first();

    expect($intake)->not->toBeNull()
        ->and($intake->source)->toBe(IntakeSource::Embed)
        ->and($intake->status)->toBe(IntakeStatus::Sent)
        ->and($intake->created_by)->toBe($ctx['older']->id)
        ->and($intake->company_id)->toBe($ctx['company']->id)
        ->and($intake->customer_access_enabled)->toBeTrue()
        ->and($intake->isTokenValid())->toBeTrue();

    $response->assertRedirect($intake->customerUrl())
        ->assertStatus(303);

    expect(parse_url($intake->customerUrl(), PHP_URL_PATH))->toStartWith('/o/');

    Mail::assertSent(CustomerIntakeLinkMail::class, function (CustomerIntakeLinkMail $mail): bool {
        return $mail->hasTo('sara.klant@example.com');
    });

    Queue::assertNotPushed(DeriveIntentFromRequestJob::class);
    Bus::assertNotDispatched(DeriveIntentFromRequestJob::class);
    expect(AiRun::query()->count())->toBe(0);

    $created = IntakeActivityEvent::query()
        ->where('intake_id', $intake->id)
        ->where('event', 'intake_created')
        ->first();

    expect($created)->not->toBeNull()
        ->and($created->actor_type)->toBe('embed')
        ->and($created->actor_id)->toBeNull()
        ->and($created->properties['source'] ?? null)->toBe('embed')
        ->and($created->properties['embed_referer_host'] ?? null)->toBe('www.example.nl')
        ->and(json_encode($created->properties))->not->toContain('sara.klant@example.com')
        ->and(json_encode($created->properties))->not->toContain($intake->access_token);
});

test('honeypot filled silently skips intake creation', function () {
    Mail::fake();
    $ctx = embedCompanyReady();

    postEmbed($ctx['key'], ['website' => 'https://spam.example'])
        ->assertRedirect(url('/'));

    expect(Intake::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

test('wrong origin is rejected', function () {
    $ctx = embedCompanyReady();

    postEmbed($ctx['key'], [], [
        'Origin' => 'https://evil.example',
        'Referer' => 'https://evil.example/page',
    ])->assertForbidden();

    expect(Intake::query()->count())->toBe(0);
});

test('missing origin and referer is rejected', function () {
    $ctx = embedCompanyReady();

    // Geen from()/Referer: anders telt de app-host als toegestaan (iframe same-origin).
    $this->post(route('embed.store', ['public_key' => $ctx['key']]), embedPayload())
        ->assertForbidden();

    expect(Intake::query()->count())->toBe(0);
});

test('same-origin app host is accepted for iframe form posts', function () {
    Mail::fake();
    Queue::fake();
    $ctx = embedCompanyReady();

    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

    postEmbed($ctx['key'], [
        'customer_email' => 'same-origin@example.com',
    ], [
        'Origin' => 'https://'.$appHost,
        'Referer' => 'https://'.$appHost.'/embed/'.$ctx['key'],
    ])->assertStatus(303);

    expect(Intake::query()->where('customer_email', 'same-origin@example.com')->count())->toBe(1);
});

test('daily limit returns 429 with dutch message', function () {
    Mail::fake();
    Queue::fake();
    $ctx = embedCompanyReady(['embed_daily_limit' => 1]);

    postEmbed($ctx['key'], ['customer_email' => 'eerste@example.com'])->assertStatus(303);

    $response = postEmbed($ctx['key'], ['customer_email' => 'tweede@example.com']);

    $response->assertStatus(429)
        ->assertSee('maximum aantal opnames', false);

    expect(Intake::query()->count())->toBe(1);
});

test('dedup reuses open intake for same company email', function () {
    Mail::fake();
    Queue::fake();
    $ctx = embedCompanyReady();

    postEmbed($ctx['key'])->assertStatus(303);
    $first = Intake::query()->where('customer_email', 'sara.klant@example.com')->firstOrFail();

    $response = postEmbed($ctx['key'], [
        'customer_name' => 'Andere Naam',
        'customer_email' => 'Sara.Klant@example.com',
    ]);

    $response->assertRedirect($first->customerUrl())->assertStatus(303);

    expect(Intake::query()->whereRaw('lower(customer_email) = ?', ['sara.klant@example.com'])->count())->toBe(1)
        ->and(Intake::query()->count())->toBe(1);
});

test('embed start is rate limited per ip', function () {
    Mail::fake();
    Queue::fake();
    config(['intake.embed.throttle_per_hour' => 2]);
    RateLimiter::clear('embed-ip:'.hash('sha256', '127.0.0.1'));
    RateLimiter::clear('embed-key:'.hash('sha256', 'embed-test-key-aaaaaaaaaaaa'));

    $ctx = embedCompanyReady();

    postEmbed($ctx['key'], ['customer_email' => 'een@example.com'])->assertStatus(303);
    postEmbed($ctx['key'], ['customer_email' => 'twee@example.com'])->assertStatus(303);
    postEmbed($ctx['key'], ['customer_email' => 'drie@example.com'])->assertTooManyRequests();
});
