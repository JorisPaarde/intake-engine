<?php

declare(strict_types=1);

use App\Domains\Intake\Data\EnergyLabel;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('services.pdok.enabled', true);
    config()->set('services.pdok.search_base_url', 'https://api.pdok.test/search');
    config()->set('services.pdok.bag_base_url', 'https://api.pdok.test/bag');
    config()->set('services.pdok.aerial_enabled', false);
    config()->set('services.threedbag.enabled', false);
    config()->set('services.ep_online.enabled', true);
    config()->set('services.ep_online.key', 'epo-test-key');
    config()->set('services.ep_online.base_url', 'https://ep-online.test');
    Storage::fake('local');
    $this->seed(IntakeTemplateSeeder::class);
});

/** @return array<string, mixed> */
function epoLabel(array $overrides = []): array
{
    return [
        'Registratiedatum' => '2023-05-11T00:00:00',
        'Geldig_tot' => '2033-05-11T00:00:00',
        'Energieklasse' => 'B',
        'Energiebehoefte' => 78.5,
        'Gebouwtype' => 'Rijwoning tussen',
        'Gebouwklasse' => 'W',
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>|null  $labels  null = geen geregistreerd label (404)
 * @param  int  $status  afwijkende status om een storing na te bootsen
 */
function fakeEnrichmentWith(?array $labels = null, int $status = 200): void
{
    Http::fake(function (Request $request) use ($labels, $status) {
        if (str_contains($request->url(), 'ep-online.test')) {
            if ($status !== 200) {
                return Http::response([], $status);
            }

            return $labels === null
                ? Http::response([], 404)
                : Http::response($labels);
        }

        if (str_contains($request->url(), '/lookup') || str_contains($request->url(), '/free')) {
            return Http::response(['response' => ['docs' => [[
                'id' => 'adr-8f4d573be765b4c80dd635ba73747903',
                'weergavenaam' => 'Damrak 1, 1012LG Amsterdam',
                'straatnaam' => 'Damrak',
                'huisnummer' => 1,
                'postcode' => '1012LG',
                'woonplaatsnaam' => 'Amsterdam',
                'adresseerbaarobject_id' => '0363010012111931',
            ]]]]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response([
                'features' => [[
                    'properties' => [
                        'identificatie' => '0363010012111931',
                        'gebruiksdoel' => 'woonfunctie',
                        'pand.href' => ['https://api.pdok.test/bag/collections/pand/items/pand-uuid'],
                    ],
                    'geometry' => ['type' => 'Point', 'coordinates' => [4.898, 52.377]],
                ]],
            ]);
        }

        if (str_contains($request->url(), '/collections/pand/items/pand-uuid')) {
            return Http::response(['properties' => ['identificatie' => '0363100012185508', 'bouwjaar' => 1962]]);
        }

        return Http::response([], 404);
    });
}

function createEnrichedIntake(string $email): Intake
{
    $user = User::factory()->create();

    test()->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Label Klant',
        'customer_email' => $email,
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012LG',
        'address_city' => 'Amsterdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ]);

    return Intake::query()->where('customer_email', $email)->firstOrFail();
}

/** @return list<string> */
function stepKeysFor(Intake $intake): array
{
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    return collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key')->all();
}

test('a registered energy label answers both insulation and building type', function () {
    fakeEnrichmentWith([epoLabel()]);

    $intake = createEnrichedIntake('label@example.com');
    $facts = $intake->externalFacts()->pluck('value', 'fact_key');

    // 78.5 kWh/m2 valt in de middenband.
    expect($intake->answers()->where('question_key', 'insulation_indication')->firstOrFail()->value)->toBe(['value' => 'average'])
        ->and($intake->answers()->where('question_key', 'building_type')->firstOrFail()->value)->toBe(['value' => 'terraced'])
        ->and($facts['energy_label']['value'])->toBe('B')
        ->and($facts['energy_demand'])->toBe(['number' => 78.5, 'unit' => 'kWh/m²·jr']);

    expect(stepKeysFor($intake))->not->toContain('insulation_indication')
        ->and(stepKeysFor($intake))->not->toContain('building_type');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'ep-online.test')
        && $request->hasHeader('Authorization', 'epo-test-key')
        && str_contains($request->url(), '/AdresseerbaarObject/0363010012111931'));
});

test('insulation follows the energy demand rather than the label letter', function () {
    // Label A door zonnepanelen, maar een slechte schil: de vraag mag niet 'good' worden.
    fakeEnrichmentWith([epoLabel(['Energieklasse' => 'A', 'Energiebehoefte' => 165.0])]);

    $intake = createEnrichedIntake('zonnepanelen@example.com');

    expect($intake->answers()->where('question_key', 'insulation_indication')->firstOrFail()->value)
        ->toBe(['value' => 'poor']);
});

test('an old label without energy demand falls back to the letter', function () {
    fakeEnrichmentWith([epoLabel(['Energiebehoefte' => null, 'Energieklasse' => 'F'])]);

    $intake = createEnrichedIntake('oudlabel@example.com');

    expect($intake->answers()->where('question_key', 'insulation_indication')->firstOrFail()->value)
        ->toBe(['value' => 'poor']);
});

test('an unrecognised building type leaves the question standing', function () {
    fakeEnrichmentWith([epoLabel(['Gebouwtype' => 'Woonwagen met aanbouw'])]);

    $intake = createEnrichedIntake('woonwagen@example.com');

    expect($intake->answers()->where('question_key', 'building_type')->exists())->toBeFalse()
        ->and(stepKeysFor($intake))->toContain('building_type')
        // De isolatie is wél bekend, dus die vraag vervalt gewoon.
        ->and(stepKeysFor($intake))->not->toContain('insulation_indication');
});

test('the most recently registered label wins over an older one', function () {
    fakeEnrichmentWith([
        epoLabel(['Registratiedatum' => '2015-01-01T00:00:00', 'Energiebehoefte' => 210.0]),
        epoLabel(['Registratiedatum' => '2024-09-30T00:00:00', 'Energiebehoefte' => 41.0]),
    ]);

    $intake = createEnrichedIntake('herlabeld@example.com');

    expect($intake->answers()->where('question_key', 'insulation_indication')->firstOrFail()->value)
        ->toBe(['value' => 'good']);
});

test('an address without a registered label keeps both questions', function () {
    fakeEnrichmentWith(null);

    $intake = createEnrichedIntake('geenlabel@example.com');

    expect($intake->answers()->where('question_key', 'insulation_indication')->exists())->toBeFalse()
        ->and(stepKeysFor($intake))->toContain('insulation_indication')
        ->and(stepKeysFor($intake))->toContain('building_type')
        // De BAG-verrijking moet gewoon geslaagd zijn.
        ->and($intake->externalFacts()->where('fact_key', 'building_year')->exists())->toBeTrue();
});

test('an EP-Online outage never blocks the rest of the enrichment', function () {
    fakeEnrichmentWith(status: 500);

    $intake = createEnrichedIntake('epostoring@example.com');

    expect($intake->externalFacts()->where('fact_key', 'building_year')->exists())->toBeTrue()
        ->and($intake->answers()->where('question_key', 'build_year')->exists())->toBeTrue();
});

test('a utility building class maps straight to commercial', function () {
    $label = new EnergyLabel(
        energyClass: 'C',
        energyDemandKwhM2: null,
        buildingType: 'Kantoorgebouw',
        buildingClass: 'U',
        registeredAt: null,
        validUntil: null,
    );

    expect($label->buildingTypeOption())->toBe('commercial');
});
