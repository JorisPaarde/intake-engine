<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Mail\CustomerIntakeLinkMail;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\EmbedFormToken;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Livewire\Customer\IntakeWizard;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(IntakeTemplateSeeder::class);

    config()->set('services.pdok.enabled', true);
    config()->set('services.pdok.search_base_url', 'https://api.pdok.test/search');
    config()->set('services.pdok.bag_base_url', 'https://api.pdok.test/bag');
    config()->set('services.pdok.timeout_seconds', 1);
    config()->set('services.pdok.aerial_enabled', false);
    config()->set('services.bag_api.enabled', false);
    config()->set('services.ep_online.enabled', false);
    config()->set('services.threedbag.enabled', false);
});

/**
 * @return array{company: Company, key: string}
 */
function embedBagCompany(): array
{
    $company = Company::factory()->withEmbedKey('embed-bag-key-bbbbbbbbbbbb')->create([
        'embed_enabled' => true,
        'embed_allowed_origins' => ['www.example.nl'],
        'embed_daily_limit' => 20,
        'name' => 'BAG Test Koeling',
    ]);
    User::factory()->for($company)->create();

    return [
        'company' => $company->fresh(),
        'key' => (string) $company->embed_public_key,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function embedBagPayload(string $key, array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Test Joris',
        'customer_email' => 'joris.bag@example.com',
        'customer_phone' => null,
        'address_postal_code' => '2037GR',
        'address_house_number' => 273,
        'address_house_number_addition' => null,
        'privacy_consent' => '1',
        'website' => '',
        EmbedFormToken::FIELD => app(EmbedFormToken::class)->issue($key),
    ], $overrides);
}

function fakeBernadotteBagPdok(): void
{
    Http::fake(function (Request $request) {
        $data = $request->data();

        if (str_contains($request->url(), '/suggest')) {
            return Http::response([
                'response' => ['docs' => [[
                    'id' => 'adr-47f56cda8f286d7ec9475e170e375ba6',
                    'weergavenaam' => 'Bernadottelaan 273, 2037GR Haarlem',
                    'straatnaam' => 'Bernadottelaan',
                    'huisnummer' => 273,
                    'postcode' => '2037GR',
                    'woonplaatsnaam' => 'Haarlem',
                ]]],
            ]);
        }

        if (str_contains($request->url(), '/lookup')) {
            return Http::response([
                'response' => ['docs' => [[
                    'id' => 'adr-47f56cda8f286d7ec9475e170e375ba6',
                    'weergavenaam' => 'Bernadottelaan 273, 2037GR Haarlem',
                    'straatnaam' => 'Bernadottelaan',
                    'huisnummer' => 273,
                    'postcode' => '2037GR',
                    'woonplaatsnaam' => 'Haarlem',
                    'gemeentenaam' => 'Haarlem',
                    'provincienaam' => 'Noord-Holland',
                    'adresseerbaarobject_id' => '0392010000005891',
                    'gekoppeld_perceel' => ['HLM02-X-1693'],
                ]]],
            ]);
        }

        if (str_contains($request->url(), '/free')) {
            return Http::response([
                'response' => ['docs' => [[
                    'id' => 'adr-47f56cda8f286d7ec9475e170e375ba6',
                    'weergavenaam' => 'Bernadottelaan 273, 2037GR Haarlem',
                    'straatnaam' => 'Bernadottelaan',
                    'huisnummer' => 273,
                    'postcode' => '2037GR',
                    'woonplaatsnaam' => 'Haarlem',
                    'gemeentenaam' => 'Haarlem',
                    'provincienaam' => 'Noord-Holland',
                    'adresseerbaarobject_id' => '0392010000005891',
                ]]],
            ]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items') && isset($data['identificatie'])) {
            return Http::response([
                'features' => [[
                    'properties' => [
                        'identificatie' => '0392010000005891',
                        'oppervlakte' => 149,
                        'gebruiksdoel' => 'woonfunctie',
                        'pand.href' => ['https://api.pdok.test/bag/collections/pand/items/pand-bern'],
                    ],
                    'geometry' => ['type' => 'Point', 'coordinates' => [4.66584971, 52.35367818]],
                ]],
            ]);
        }

        if (str_contains($request->url(), '/collections/pand/items/pand-bern')) {
            return Http::response([
                'properties' => [
                    'identificatie' => '0392100000073249',
                    'bouwjaar' => 1971,
                ],
            ]);
        }

        // Geometry inference for woningtype (terraced) — same shape as BuildingTypeEnrichmentTest.
        if (str_contains($request->url(), '/collections/pand/items') && isset($data['identificatie'])) {
            return Http::response(['features' => [embedEnrichmentBuildingFeature('pand-bern', '0392100000073249', 0, 0, 6, 10)]]);
        }

        if (str_contains($request->url(), '/collections/pand/items')) {
            return Http::response([
                'numberMatched' => 3,
                'numberReturned' => 3,
                'links' => [],
                'features' => [
                    embedEnrichmentBuildingFeature('left-feature', '0392100000073248', -6, 0, 0, 10),
                    embedEnrichmentBuildingFeature('pand-bern', '0392100000073249', 0, 0, 6, 10),
                    embedEnrichmentBuildingFeature('right-feature', '0392100000073250', 6, 0, 12, 10),
                ],
            ]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response([
                'numberMatched' => 1,
                'numberReturned' => 1,
                'links' => [],
                'features' => [[
                    'properties' => [
                        'pand.href' => ['https://api.pdok.test/bag/collections/pand/items/pand-bern'],
                        'gebruiksdoel' => ['woonfunctie'],
                    ],
                ]],
            ]);
        }

        return Http::response([], 404);
    });
}

/** @return array<string, mixed> */
function embedEnrichmentBuildingFeature(string $featureId, string $buildingId, float $minX, float $minY, float $maxX, float $maxY): array
{
    return [
        'id' => $featureId,
        'properties' => ['identificatie' => $buildingId],
        'geometry' => [
            'type' => 'Polygon',
            'coordinates' => [[
                [$minX, $minY], [$maxX, $minY], [$maxX, $maxY], [$minX, $maxY], [$minX, $minY],
            ]],
        ],
    ];
}

test('embed intake with valid address gets BAG building year and skips build_year question', function () {
    Mail::fake();
    Queue::fake();
    fakeBernadotteBagPdok();

    $ctx = embedBagCompany();

    $response = $this->withHeaders([
        'Origin' => 'https://www.example.nl',
        'Referer' => 'https://www.example.nl/airco',
    ])->post(route('embed.store', ['public_key' => $ctx['key']]), embedBagPayload($ctx['key']));

    $intake = Intake::query()->where('customer_email', 'joris.bag@example.com')->firstOrFail();

    $response->assertRedirect($intake->customerUrl())->assertStatus(303);

    expect($intake->address_line)->toBe('Bernadottelaan 273')
        ->and($intake->address_city)->toBe('Haarlem')
        ->and($intake->address_postal_code)->toBe('2037GR')
        ->and($intake->address_house_number)->toBe(273)
        ->and($intake->fullAddress())->toContain('Bernadottelaan 273')
        ->and($intake->fullAddress())->toContain('Haarlem')
        ->and($intake->fullAddress())->not->toContain('Onbekend')
        ->and($intake->hasKnownHousingFacts())->toBeTrue()
        ->and($intake->externalFacts()->where('fact_key', 'building_year')->firstOrFail()->value)
        ->toBe(['number' => 1971])
        ->and($intake->externalFacts()->where('fact_key', 'floor_area_m2')->firstOrFail()->value)
        ->toBe(['number' => 149, 'unit' => 'm²'])
        ->and($intake->answers()->where('question_key', 'build_year')->firstOrFail()->value)
        ->toBe(['number' => 1971])
        ->and($intake->answers()->where('question_key', 'building_type')->firstOrFail()->value)
        ->toBe(['value' => 'terraced']);

    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules', 'template'])
        ->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh() ?? $intake, $version))
        ->pluck('question_key')
        ->all();

    expect($stepKeys)->not->toContain('build_year')
        ->and($stepKeys)->not->toContain('building_type');

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertOk()
        ->assertSee('Bernadottelaan 273', false)
        ->assertSee('Haarlem', false)
        ->assertDontSee('Onbekend', false)
        ->assertSee('We gebruiken al bekende woninggegevens', false)
        ->assertSee('Bouwjaar', false)
        ->assertSee('1971', false)
        ->assertSee('Woningtype', false)
        ->assertSee('Tussenwoning', false)
        ->assertDontSee('Beantwoord de vragen hieronder — dat kan ook op je telefoon', false);

    Mail::assertSent(CustomerIntakeLinkMail::class, function (CustomerIntakeLinkMail $mail) use ($intake): bool {
        $html = $mail->render();

        return $mail->hasTo('joris.bag@example.com')
            && str_contains($html, 'Bekende woninggegevens hebben we al toegevoegd')
            && $intake->hasKnownHousingFacts();
    });
});

test('embed rejects unknown address like 2037GR 1 with a clear Dutch message', function () {
    Mail::fake();
    Queue::fake();

    Http::fake([
        'https://api.pdok.test/search/suggest*' => Http::response([
            'response' => ['docs' => []],
        ]),
    ]);

    $ctx = embedBagCompany();

    $response = $this->withHeaders([
        'Origin' => 'https://www.example.nl',
        'Referer' => 'https://www.example.nl/airco',
    ])->post(route('embed.store', ['public_key' => $ctx['key']]), embedBagPayload($ctx['key'], [
        'customer_email' => 'onbekend.adres@example.com',
        'address_postal_code' => '2037GR',
        'address_house_number' => 1,
    ]));

    $response->assertStatus(422)
        ->assertSee('We konden dit adres niet vinden', false)
        ->assertSee('value="2037GR"', false)
        ->assertDontSee('Onbekend', false);

    expect(Intake::query()->where('customer_email', 'onbekend.adres@example.com')->count())->toBe(0);
    Mail::assertNothingSent();
});

test('wizard copy omits known-housing claim when BAG facts are missing', function () {
    $company = Company::factory()->create(['name' => 'Zonder BAG']);
    $user = User::factory()->for($company)->create();

    $intake = app(CreateIntake::class)->handle($user, [
        'customer_name' => 'Zonder Feiten',
        'customer_email' => 'zonder.feiten@example.com',
        'address_line' => '2037GR 1',
        'address_postal_code' => '2037GR',
        'address_house_number' => 1,
        'address_city' => 'Onbekend',
    ]);

    expect($intake->hasKnownHousingFacts())->toBeFalse();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertOk()
        ->assertDontSee('We gebruiken al bekende woninggegevens', false)
        ->assertDontSee('Jij laat alleen zien wat we nog nodig hebben', false)
        ->assertSee('Beantwoord de vragen hieronder — dat kan ook op je telefoon', false);
});
