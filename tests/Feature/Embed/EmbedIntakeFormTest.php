<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\DeriveIntentFromRequestJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Mail\CustomerIntakeLinkMail;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Services\EmbedFormToken;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(IntakeTemplateSeeder::class);

    config()->set('services.pdok.enabled', true);
    config()->set('services.pdok.search_base_url', 'https://api.pdok.test/search');
    config()->set('services.pdok.bag_base_url', 'https://api.pdok.test/bag');
    config()->set('services.pdok.timeout_seconds', 1);
    config()->set('services.pdok.aerial_enabled', false);
    config()->set('services.bag_api.enabled', false);

    // Default embed payload (1234AB / 10) must resolve via PDOK suggest before create.
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/suggest')) {
            return Http::response([
                'response' => ['docs' => [[
                    'id' => 'adr-embed-default-1234ab-10',
                    'weergavenaam' => 'Voorbeeldstraat 10, 1234AB Voorbeeldstad',
                    'straatnaam' => 'Voorbeeldstraat',
                    'huisnummer' => 10,
                    'postcode' => '1234AB',
                    'woonplaatsnaam' => 'Voorbeeldstad',
                ]]],
            ]);
        }

        if (str_contains($request->url(), '/lookup')) {
            return Http::response([
                'response' => ['docs' => [[
                    'id' => 'adr-embed-default-1234ab-10',
                    'weergavenaam' => 'Voorbeeldstraat 10, 1234AB Voorbeeldstad',
                    'straatnaam' => 'Voorbeeldstraat',
                    'huisnummer' => 10,
                    'postcode' => '1234AB',
                    'woonplaatsnaam' => 'Voorbeeldstad',
                    'gemeentenaam' => 'Voorbeeldstad',
                    'provincienaam' => 'Noord-Holland',
                    'adresseerbaarobject_id' => '0363010012111931',
                ]]],
            ]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response([
                'features' => [[
                    'properties' => [
                        'identificatie' => '0363010012111931',
                        'oppervlakte' => 90,
                        'gebruiksdoel' => 'woonfunctie',
                        'pand.href' => ['https://api.pdok.test/bag/collections/pand/items/pand-embed-default'],
                    ],
                    'geometry' => ['type' => 'Point', 'coordinates' => [4.9, 52.3]],
                ]],
            ]);
        }

        if (str_contains($request->url(), '/collections/pand/items/pand-embed-default')) {
            return Http::response([
                'properties' => [
                    'identificatie' => '0363100012111931',
                    'bouwjaar' => 1980,
                ],
            ]);
        }

        return Http::response([], 404);
    });
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
function embedPayload(string $key, array $overrides = []): array
{
    $hasTokenOverride = array_key_exists(EmbedFormToken::FIELD, $overrides);
    $tokenOverride = $hasTokenOverride ? $overrides[EmbedFormToken::FIELD] : null;
    unset($overrides[EmbedFormToken::FIELD]);

    $payload = array_merge([
        'customer_name' => 'Sara Klant',
        'customer_email' => 'sara.klant@example.com',
        'customer_phone' => '0612345678',
        'address_postal_code' => '1234AB',
        'address_house_number' => '10',
        'address_house_number_addition' => null,
        'privacy_consent' => '1',
        'website' => '',
        'address_confirmed' => '1',
    ], $overrides);

    if ($hasTokenOverride) {
        if ($tokenOverride !== null) {
            $payload[EmbedFormToken::FIELD] = $tokenOverride;
        }
    } else {
        $payload[EmbedFormToken::FIELD] = app(EmbedFormToken::class)->issue($key);
    }

    return $payload;
}

function postEmbed(string $key, array $payload = [], array $headers = [])
{
    $defaultHeaders = [
        'Origin' => 'https://www.example.nl',
        'Referer' => 'https://www.example.nl/airco',
    ];

    return test()->withHeaders(array_merge($defaultHeaders, $headers))
        ->post(route('embed.store', ['public_key' => $key]), embedPayload($key, $payload));
}

test('embed form shows for enabled key with frame-ancestors header and signed token', function () {
    $ctx = embedCompanyReady();

    $response = $this->get(route('embed.show', ['public_key' => $ctx['key']]));

    $response->assertOk()
        ->assertSee('Start je opname', false)
        ->assertSee('Voorbeeld Koeling', false)
        ->assertDontSee('verwerkingsverantwoordelijke', false)
        ->assertDontSee('Zonder dit vinkje kunnen we de opname niet starten.', false)
        ->assertSee('toestemming om mijn gegevens en foto', false)
        ->assertSee('>Privacy</a>', false)
        ->assertSee('aria-label="Toevoeging, optioneel"', false)
        ->assertSee('>Toev.</span>', false)
        ->assertSee('Toevoeging, optioneel', false)
        ->assertSee('gegevens en foto', false)
        ->assertSee('name="website"', false)
        ->assertSee('name="embed_form_token"', false)
        ->assertDontSee('@csrf', false)
        ->assertDontSee('name="_token"', false)
        ->assertDontSee('type="number"', false)
        ->assertSee('id="address_house_number"', false)
        ->assertSee('inputmode="numeric"', false)
        ->assertSee('autocomplete="address-line2"', false)
        ->assertSee('autocomplete="address-line3"', false)
        ->assertSee('Controleer adres', false)
        ->assertSee('intake-engine-embed', false)
        ->assertDontSee('target="_top"', false)
        ->assertHeader('Content-Security-Policy');

    // Explicit AVG checkbox must start unchecked (no checked attribute on privacy_consent).
    expect($response->getContent())->not->toMatch('/id="privacy_consent"[^>]*checked/');

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

test('first submit shows address confirmation without creating an intake', function () {
    Mail::fake();
    $ctx = embedCompanyReady();

    $response = postEmbed($ctx['key'], ['address_confirmed' => null]);

    $response->assertOk()
        ->assertSee('Is dit je adres?', false)
        ->assertSee('Voorbeeldstraat 10, 1234 AB Voorbeeldstad', false)
        ->assertSee('Ja, klopt', false)
        ->assertSee('Aanpassen', false)
        ->assertSee('name="address_confirmed"', false)
        ->assertSee('target="_top"', false)
        ->assertSee('intake-engine-embed', false);

    expect(Intake::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

test('aanpassen returns to the form with preserved fields', function () {
    $ctx = embedCompanyReady();

    $response = postEmbed($ctx['key'], [
        'address_confirmed' => null,
        'embed_action' => 'edit',
        'customer_name' => 'Sara Bewerkt',
    ]);

    $response->assertOk()
        ->assertSee('Start je opname', false)
        ->assertSee('value="Sara Bewerkt"', false)
        ->assertSee('Controleer adres', false)
        ->assertDontSee('Is dit je adres?', false);

    expect(Intake::query()->count())->toBe(0);
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

    $privacy = $intake->answers()->where('question_key', 'privacy_consent')->whereNull('section_instance_key')->first();
    expect($privacy)->not->toBeNull()
        ->and($privacy?->value['bool'] ?? null)->toBeTrue()
        ->and($privacy?->prefill_source)->toBeNull();

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

test('wheel-safe house number rejects non-numeric text and accepts digit string', function () {
    Mail::fake();
    $ctx = embedCompanyReady();

    postEmbed($ctx['key'], [
        'address_house_number' => '12a',
        'address_confirmed' => null,
    ])->assertStatus(422)
        ->assertSee('Vul een geldig huisnummer in', false);

    expect(Intake::query()->count())->toBe(0);

    postEmbed($ctx['key'], [
        'address_house_number' => '10',
        'address_confirmed' => null,
    ])->assertOk()
        ->assertSee('Is dit je adres?', false);
});

test('honeypot filled silently skips intake creation', function () {
    Mail::fake();
    $ctx = embedCompanyReady();

    postEmbed($ctx['key'], ['website' => 'https://spam.example'])
        ->assertOk()
        ->assertSee('We hebben je de link naar je opname gemaild.', false);

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

    $this->post(route('embed.store', ['public_key' => $ctx['key']]), embedPayload($ctx['key']))
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

test('dedup resends mail and shows confirmation without leaking token', function () {
    Mail::fake();
    Queue::fake();
    $ctx = embedCompanyReady();

    postEmbed($ctx['key'])->assertStatus(303);
    $first = Intake::query()->where('customer_email', 'sara.klant@example.com')->firstOrFail();
    $token = $first->access_token;

    Mail::fake();

    $response = postEmbed($ctx['key'], [
        'customer_name' => 'Andere Naam',
        'customer_email' => 'Sara.Klant@example.com',
    ]);

    $response->assertOk()
        ->assertSee('We hebben je de link naar je opname gemaild.', false)
        ->assertDontSee('/o/', false)
        ->assertDontSee($token, false);

    expect($response->headers->get('Location'))->toBeNull()
        ->and(Intake::query()->whereRaw('lower(customer_email) = ?', ['sara.klant@example.com'])->count())->toBe(1)
        ->and(Intake::query()->count())->toBe(1);

    Mail::assertSent(CustomerIntakeLinkMail::class, function (CustomerIntakeLinkMail $mail): bool {
        return $mail->hasTo('sara.klant@example.com');
    });
});

test('dedup does not count against daily limit', function () {
    Mail::fake();
    Queue::fake();
    $ctx = embedCompanyReady(['embed_daily_limit' => 1]);

    postEmbed($ctx['key'], ['customer_email' => 'limiet@example.com'])->assertStatus(303);
    postEmbed($ctx['key'], ['customer_email' => 'limiet@example.com'])
        ->assertOk()
        ->assertSee('We hebben je de link naar je opname gemaild.', false);

    expect(Intake::query()->count())->toBe(1);
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

test('post without session cookie but with valid form token succeeds', function () {
    Mail::fake();
    Queue::fake();
    $ctx = embedCompanyReady();

    $this->withHeaders([
        'Origin' => 'https://www.example.nl',
        'Referer' => 'https://www.example.nl/airco',
    ])
        ->post(route('embed.store', ['public_key' => $ctx['key']]), embedPayload($ctx['key'], [
            'customer_email' => 'stateless@example.com',
        ]))
        ->assertStatus(303);

    expect(Intake::query()->where('customer_email', 'stateless@example.com')->count())->toBe(1);
});

test('post without form token returns 419 with dutch message', function () {
    $ctx = embedCompanyReady();

    postEmbed($ctx['key'], [EmbedFormToken::FIELD => null])
        ->assertStatus(419)
        ->assertSee('Je formulier is verlopen of ongeldig', false);

    expect(Intake::query()->count())->toBe(0);
});

test('post with tampered form token returns 419', function () {
    $ctx = embedCompanyReady();
    $token = app(EmbedFormToken::class)->issue($ctx['key']);
    $tampered = $token.'x';

    postEmbed($ctx['key'], [EmbedFormToken::FIELD => $tampered])
        ->assertStatus(419)
        ->assertSee('Je formulier is verlopen of ongeldig', false);

    expect(Intake::query()->count())->toBe(0);
});

test('post with expired form token returns 419', function () {
    $ctx = embedCompanyReady();
    $token = app(EmbedFormToken::class)->issue($ctx['key']);

    $this->travel(EmbedFormToken::MAX_AGE_SECONDS + 60)->seconds();

    postEmbed($ctx['key'], [EmbedFormToken::FIELD => $token])
        ->assertStatus(419)
        ->assertSee('Je formulier is verlopen of ongeldig', false);

    expect(Intake::query()->count())->toBe(0);
});

test('validation failure without session returns 422 with messages and preserved input', function () {
    Mail::fake();
    $ctx = embedCompanyReady();

    $response = postEmbed($ctx['key'], [
        'customer_name' => 'Sara Klant',
        'customer_email' => 'sara.klant@example.com',
        'address_postal_code' => '123',
        'address_house_number' => 10,
        'privacy_consent' => '1',
    ]);

    $response->assertStatus(422)
        ->assertSee('Vul een geldige postcode in', false)
        ->assertSee('value="Sara Klant"', false)
        ->assertSee('name="embed_form_token"', false)
        ->assertSee('aria-describedby="address_postal_code-error"', false)
        ->assertDontSee('Location:', false);

    expect($response->headers->get('Location'))->toBeNull()
        ->and(Intake::query()->count())->toBe(0);
});

test('privacy consent missing shows zonder-vinkje foutmelding only in error', function () {
    Mail::fake();
    $ctx = embedCompanyReady();

    $ok = $this->get(route('embed.show', ['public_key' => $ctx['key']]));
    $ok->assertOk()
        ->assertDontSee('Zonder dit vinkje kunnen we de opname niet starten.', false);

    $response = postEmbed($ctx['key'], [
        'privacy_consent' => null,
    ]);

    $response->assertStatus(422)
        ->assertSee('Zonder dit vinkje kunnen we de opname niet starten.', false)
        ->assertDontSee('verwerkingsverantwoordelijke', false);

    expect(Intake::query()->count())->toBe(0);
});
