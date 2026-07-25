<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('prefills a terraced building type from BAG geometry and removes the customer question', function () {
    config()->set('services.pdok.enabled', true);
    config()->set('services.pdok.search_base_url', 'https://api.pdok.test/search');
    config()->set('services.pdok.bag_base_url', 'https://api.pdok.test/bag');
    config()->set('services.pdok.aerial_enabled', false);
    config()->set('services.threedbag.enabled', false);
    config()->set('services.ep_online.enabled', false);
    $this->seed(IntakeTemplateSeeder::class);

    Http::fake(function (Request $request) {
        $data = $request->data();

        if (str_contains($request->url(), '/lookup')) {
            return Http::response(['response' => ['docs' => [[
                'id' => 'adr-8f4d573be765b4c80dd635ba73747903',
                'weergavenaam' => 'Teststraat 10, 1234AB Testdam',
                'straatnaam' => 'Teststraat',
                'huisnummer' => 10,
                'postcode' => '1234AB',
                'woonplaatsnaam' => 'Testdam',
                'gemeentenaam' => 'Testdam',
                'provincienaam' => 'Noord-Holland',
                'adresseerbaarobject_id' => '0363010012111931',
                'gekoppeld_perceel' => [],
            ]]]]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items') && isset($data['identificatie'])) {
            return Http::response(['features' => [[
                'properties' => [
                    'identificatie' => '0363010012111931',
                    'oppervlakte' => 120,
                    'gebruiksdoel' => 'woonfunctie',
                    'pand.href' => ['https://api.pdok.test/bag/collections/pand/items/target-feature'],
                ],
                'geometry' => ['type' => 'Point', 'coordinates' => [5.0, 52.0]],
            ]]]);
        }

        if (str_contains($request->url(), '/collections/pand/items/target-feature')) {
            return Http::response([
                'properties' => ['identificatie' => '0363100012185508', 'bouwjaar' => 1971],
            ]);
        }

        if (str_contains($request->url(), '/collections/pand/items') && isset($data['identificatie'])) {
            return Http::response(['features' => [enrichmentBuildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10)]]);
        }

        if (str_contains($request->url(), '/collections/pand/items')) {
            return Http::response(['features' => [
                enrichmentBuildingFeature('left-feature', '0363100012185507', -6, 0, 0, 10),
                enrichmentBuildingFeature('target-feature', '0363100012185508', 0, 0, 6, 10),
                enrichmentBuildingFeature('right-feature', '0363100012185509', 6, 0, 12, 10),
            ]]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response(['features' => [[
                'properties' => [
                    'pand.href' => ['https://api.pdok.test/bag/collections/pand/items/target-feature'],
                ],
            ]]]);
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'BAG Woning',
        'customer_email' => 'woning@example.com',
        'customer_phone' => '0612345678',
        'address_line' => 'Teststraat 10',
        'address_postal_code' => '1234 AB',
        'address_city' => 'Testdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ])->assertRedirect();

    $intake = Intake::query()->where('customer_email', 'woning@example.com')->firstOrFail();
    $answer = $intake->answers()->where('question_key', 'building_type')->firstOrFail();
    $fact = $intake->externalFacts()->where('fact_key', 'building_type_inference')->firstOrFail();
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    expect($answer->value)->toBe(['value' => 'terraced'])
        ->and($answer->prefill_source)->toBe('pdok')
        ->and($fact->value['reason'])->toContain('weerszijden')
        ->and($stepKeys)->not->toContain('building_type');
});

/** @return array<string, mixed> */
function enrichmentBuildingFeature(string $featureId, string $buildingId, float $minX, float $minY, float $maxX, float $maxY): array
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
