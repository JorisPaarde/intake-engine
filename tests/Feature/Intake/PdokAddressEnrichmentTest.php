<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\GenerateIntakePdf;
use App\Domains\Intake\Actions\HardDeleteIntake;
use App\Domains\Intake\Actions\StartDemoIntake;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\ExternalFactPresenter;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('services.pdok.enabled', true);
    config()->set('services.pdok.search_base_url', 'https://api.pdok.test/search');
    config()->set('services.pdok.bag_base_url', 'https://api.pdok.test/bag');
    config()->set('services.pdok.timeout_seconds', 1);
    config()->set('services.pdok.aerial_enabled', true);
    config()->set('services.pdok.aerial_wms_url', 'https://api.pdok.test/aerial');
    config()->set('services.pdok.aerial_layer', 'Actueel_orthoHR');
    config()->set('services.pdok.aerial_timeout_seconds', 1);
    config()->set('services.pdok.aerial_width', 900);
    config()->set('services.pdok.aerial_height', 600);
    config()->set('services.pdok.aerial_ground_width_meters', 180);
    Storage::fake('local');
    $this->seed(IntakeTemplateSeeder::class);
});

/** @return array<string, mixed> */
function pdokAddressDocument(): array
{
    return [
        'id' => 'adr-8f4d573be765b4c80dd635ba73747903',
        'weergavenaam' => 'Damrak 1, 1012LG Amsterdam',
        'straatnaam' => 'Damrak',
        'huisnummer' => 1,
        'postcode' => '1012LG',
        'woonplaatsnaam' => 'Amsterdam',
        'gemeentenaam' => 'Amsterdam',
        'provincienaam' => 'Noord-Holland',
        'adresseerbaarobject_id' => '0363010012111931',
        'nummeraanduiding_id' => '0363200012113669',
        'gekoppeld_perceel' => ['ASD04-F-6346'],
    ];
}

function fakeAerialJpeg(): string
{
    $image = imagecreatetruecolor(900, 600);
    $background = imagecolorallocate($image, 112, 142, 103);
    imagefill($image, 0, 0, $background);

    ob_start();
    imagejpeg($image, null, 80);
    $binary = ob_get_clean();
    imagedestroy($image);

    return is_string($binary) ? $binary : '';
}

/**
 * @param  array<string, mixed>|null  $threeDBagFeature  null = 3DBAG antwoordt 404 (geen geometrie)
 */
function fakeSuccessfulPdok(int $aerialStatus = 200, ?array $threeDBagFeature = null, int $threeDBagStatus = 200): void
{
    $aerialJpeg = fakeAerialJpeg();

    Http::fake(function (Request $request) use ($aerialJpeg, $aerialStatus, $threeDBagFeature, $threeDBagStatus) {
        if (str_contains($request->url(), '3dbag')) {
            if ($threeDBagStatus !== 200) {
                return Http::response([], $threeDBagStatus);
            }

            return $threeDBagFeature === null
                ? Http::response([], 404)
                : Http::response($threeDBagFeature);
        }

        if (str_contains($request->url(), '/aerial')) {
            return $aerialStatus === 200
                ? Http::response($aerialJpeg, 200, ['Content-Type' => 'image/jpeg'])
                : Http::response([], $aerialStatus);
        }

        if (str_contains($request->url(), '/suggest')) {
            return Http::response([
                'response' => ['docs' => [pdokAddressDocument()]],
            ]);
        }

        if (str_contains($request->url(), '/lookup')) {
            return Http::response([
                'response' => ['docs' => [pdokAddressDocument()]],
            ]);
        }

        // Zonder lookup-id (o.a. de demo) zoekt de service via /free op het volledige adres.
        if (str_contains($request->url(), '/free')) {
            return Http::response([
                'response' => ['docs' => [pdokAddressDocument()]],
            ]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response([
                'features' => [[
                    'properties' => [
                        'identificatie' => '0363010012111931',
                        'oppervlakte' => 16100,
                        'gebruiksdoel' => 'bijeenkomstfunctie,logiesfunctie',
                        'pand.href' => ['https://api.pdok.test/bag/collections/pand/items/pand-uuid'],
                    ],
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [4.89803846, 52.37714446],
                    ],
                ]],
            ]);
        }

        if (str_contains($request->url(), '/collections/pand/items/pand-uuid')) {
            return Http::response([
                'properties' => [
                    'identificatie' => '0363100012185508',
                    'bouwjaar' => 1890,
                ],
            ]);
        }

        return Http::response([], 404);
    });
}

test('authenticated installer receives sanitized PDOK address suggestions', function () {
    fakeSuccessfulPdok();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('address-suggestions', ['q' => 'Damrak 1 Amsterdam']))
        ->assertOk()
        ->assertExactJson([
            'data' => [[
                'id' => 'adr-8f4d573be765b4c80dd635ba73747903',
                'label' => 'Damrak 1, 1012LG Amsterdam',
                'address_line' => 'Damrak 1',
                'postal_code' => '1012LG',
                'city' => 'Amsterdam',
            ]],
        ]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/suggest')
        && $request['fq'] === 'type:adres');
});

test('guest cannot use address suggestions', function () {
    $this->get(route('address-suggestions', ['q' => 'Damrak']))
        ->assertRedirect(route('login'));
});

test('selected address stores BAG facts and removes the redundant build-year step', function () {
    fakeSuccessfulPdok();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'BAG Klant',
        'customer_email' => 'bag@example.com',
        'customer_phone' => '0612345678',
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012 LG',
        'address_city' => 'Amsterdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ]);

    $intake = Intake::query()->where('customer_email', 'bag@example.com')->firstOrFail();
    $response->assertRedirect(route('intakes.show', $intake));

    $buildYear = $intake->answers()->where('question_key', 'build_year')->firstOrFail();
    $facts = $intake->externalFacts()->pluck('value', 'fact_key');
    $aerial = $intake->externalFacts()->where('fact_key', 'aerial_image')->firstOrFail();

    expect($intake->status)->toBe(IntakeStatus::Sent)
        ->and($intake->templateVersion->version)->toBe(9)
        ->and($intake->address_line)->toBe('Damrak 1')
        ->and($intake->address_postal_code)->toBe('1012LG')
        ->and($buildYear->value)->toBe(['number' => 1890])
        ->and($buildYear->prefill_source)->toBe('pdok')
        ->and($facts['building_year'])->toBe(['number' => 1890])
        ->and($facts['floor_area_m2'])->toBe(['number' => 16100, 'unit' => 'm²'])
        ->and($facts['usage_purposes'])->toBe(['values' => ['bijeenkomstfunctie', 'logiesfunctie']])
        ->and($aerial->source)->toBe('PDOK Luchtfoto RGB')
        ->and($aerial->source_reference)->toBe('Actueel_orthoHR')
        ->and($aerial->value['ground_width_meters'])->toBe(180)
        ->and($aerial->value['ground_height_meters'])->toBe(120);

    Storage::disk($aerial->value['media_disk'])->assertExists($aerial->value['media_path']);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    expect($stepKeys)->not->toContain('build_year');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/lookup')
        && $request['id'] === 'adr-8f4d573be765b4c80dd635ba73747903'
        && ! array_key_exists('rows', $request->data()));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/aerial')
        && $request['service'] === 'WMS'
        && $request['request'] === 'GetMap'
        && $request['layers'] === 'Actueel_orthoHR'
        && $request['crs'] === 'EPSG:3857'
        && $request['width'] === 900
        && $request['height'] === 600
        && count(explode(',', (string) $request['bbox'])) === 4);

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Automatisch verzamelde informatie')
        ->assertSee('1890')
        ->assertSee('16100 m²')
        ->assertSee('PDOK / BAG')
        ->assertSee('Luchtfoto rond de BAG-locatie van deze opname')
        ->assertSee('PDOK Luchtfoto RGB')
        ->assertSee('data:image/jpeg;base64,', false);
});

test('generated dossier contains contact data external sources uncertainty and next step', function () {
    fakeSuccessfulPdok();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Dossier Klant',
        'customer_email' => 'dossier@example.com',
        'customer_phone' => '0612345678',
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012LG',
        'address_city' => 'Amsterdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ]);

    $intake = Intake::query()->where('customer_email', 'dossier@example.com')->firstOrFail();
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules', 'template'])->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle($intake, $version);

    expect($html)->toContain('Klant en contact')
        ->toContain('dossier@example.com')
        ->toContain('Automatisch verzamelde informatie')
        ->toContain('Bouwjaar')
        ->toContain('1890')
        ->toContain('Bron:')
        ->toContain('PDOK / BAG')
        ->toContain('data:image/jpeg;base64,')
        ->toContain('Rode markering: BAG-locatie')
        ->toContain('PDOK Luchtfoto RGB')
        ->toContain('De luchtfoto geeft alleen bovenaanzicht')
        ->toContain('Voorstel volgende stap')
        ->toContain('Controleer eerst de gemarkeerde onzekerheden');

    GeneratedReport::query()->create([
        'intake_id' => $intake->id,
        'html' => $html,
        'meta' => ['contains_aerial_image' => true],
        'generated_at' => now(),
    ]);

    $report = app(GenerateIntakePdf::class)->handle($intake->fresh());

    expect($report)->not->toBeNull()
        ->and($report?->hasPdf())->toBeTrue();
    Storage::disk((string) $report?->pdf_disk)->assertExists((string) $report?->pdf_path);
});

test('aerial outage does not discard successful BAG enrichment', function () {
    fakeSuccessfulPdok(503);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Luchtfoto Offline',
        'customer_email' => 'luchtfoto-offline@example.com',
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012LG',
        'address_city' => 'Amsterdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ]);

    $intake = Intake::query()->where('customer_email', 'luchtfoto-offline@example.com')->firstOrFail();
    $status = $intake->externalFacts()->where('fact_key', 'aerial_image_status')->firstOrFail();
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules', 'template'])->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle($intake, $version);

    expect($intake->externalFacts()->where('fact_key', 'building_year')->exists())->toBeTrue()
        ->and($intake->externalFacts()->where('fact_key', 'aerial_image')->exists())->toBeFalse()
        ->and($status->value)->toBe(['status' => 'unavailable'])
        ->and($html)->toContain('De PDOK-luchtfoto was tijdelijk niet beschikbaar');
});

test('hard deleting an intake also removes its captured aerial image', function () {
    fakeSuccessfulPdok();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Te verwijderen',
        'customer_email' => 'purge-aerial@example.com',
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012LG',
        'address_city' => 'Amsterdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ]);

    $intake = Intake::query()->where('customer_email', 'purge-aerial@example.com')->firstOrFail();
    $aerial = $intake->externalFacts()->where('fact_key', 'aerial_image')->firstOrFail();
    $disk = (string) $aerial->value['media_disk'];
    $path = (string) $aerial->value['media_path'];

    Storage::disk($disk)->assertExists($path);
    app(HardDeleteIntake::class)->handle($intake);
    Storage::disk($disk)->assertMissing($path);
});

test('PDOK outage never blocks intake creation and leaves an explicit uncertainty', function () {
    Http::fake(['*' => Http::response([], 503)]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Offline Klant',
        'customer_email' => 'offline@example.com',
        'address_line' => 'Handmatige straat 2',
        'address_postal_code' => '1234AB',
        'address_city' => 'Utrecht',
    ])->assertRedirect();

    $intake = Intake::query()->where('customer_email', 'offline@example.com')->firstOrFail();
    $status = $intake->externalFacts()->where('fact_key', 'address_verification')->firstOrFail();
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules', 'template'])->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle($intake, $version);

    expect($intake->status)->toBe(IntakeStatus::Sent)
        ->and($status->value)->toBe(['status' => 'unavailable'])
        ->and($intake->answers()->where('question_key', 'build_year')->exists())->toBeFalse()
        ->and($html)->toContain('PDOK/BAG was tijdelijk niet beschikbaar')
        ->and($html)->toContain('Controleer eerst de gemarkeerde onzekerheden');
});

test('lookup id cannot replace a manually entered different address', function () {
    fakeSuccessfulPdok();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Andere Klant',
        'customer_email' => 'anders@example.com',
        'address_line' => 'Andere straat 9',
        'address_postal_code' => '9999ZZ',
        'address_city' => 'Rotterdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ]);

    $intake = Intake::query()->where('customer_email', 'anders@example.com')->firstOrFail();

    expect($intake->address_line)->toBe('Andere straat 9')
        ->and($intake->address_postal_code)->toBe('9999ZZ')
        ->and($intake->externalFacts()->where('fact_key', 'address_verification')->firstOrFail()->value)
        ->toBe(['status' => 'not_found'])
        ->and($intake->answers()->where('question_key', 'build_year')->exists())->toBeFalse();
});

test('starting a demo enriches the address so BAG-known questions are skipped', function () {
    fakeSuccessfulPdok();

    config()->set('intake.demo.enabled', true);
    config()->set('intake.demo.address.line', 'Damrak 1');
    config()->set('intake.demo.address.postal_code', '1012LG');
    config()->set('intake.demo.address.city', 'Amsterdam');

    $intake = app(StartDemoIntake::class)->handle();

    $buildYear = $intake->answers()->where('question_key', 'build_year')->firstOrFail();

    expect($intake->is_demo)->toBeTrue()
        ->and($buildYear->value)->toBe(['number' => 1890])
        ->and($buildYear->prefill_source)->toBe('pdok')
        ->and($intake->externalFacts()->pluck('fact_key'))->toContain('building_year');

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    expect($stepKeys)->not->toContain('build_year');
});

test('a PDOK outage still lets the demo start', function () {
    Http::fake(fn () => Http::response([], 500));

    config()->set('intake.demo.enabled', true);

    $intake = app(StartDemoIntake::class)->handle();

    expect($intake->exists)->toBeTrue()
        ->and($intake->access_token)->not->toBeEmpty();
});

/** @return array<string, mixed> */
function threeDBagFeature(
    string $roofType = 'slanted',
    float $ridge = 30.682,
    float $ground = 1.579,
    bool $reliable = true,
    ?int $floors = 4,
): array {
    return [
        'feature' => [
            'CityObjects' => [
                'NL.IMBAG.Pand.0363100012185508' => [
                    'attributes' => [
                        'b3_dak_type' => $roofType,
                        'b3_h_dak_max' => $ridge,
                        'b3_h_maaiveld' => $ground,
                        'b3_bouwlagen' => $floors,
                        'b3_kwaliteitsindicator' => $reliable,
                    ],
                ],
            ],
        ],
    ];
}

test('3DBAG geometry is stored as sourced context facts with attribution', function () {
    config()->set('services.threedbag.base_url', 'https://api.3dbag.test');
    fakeSuccessfulPdok(threeDBagFeature: threeDBagFeature());
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => '3DBAG Klant',
        'customer_email' => '3dbag@example.com',
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012LG',
        'address_city' => 'Amsterdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ]);

    $intake = Intake::query()->where('customer_email', '3dbag@example.com')->firstOrFail();
    $height = $intake->externalFacts()->where('fact_key', 'building_height_m')->firstOrFail();
    $roof = $intake->externalFacts()->where('fact_key', 'roof_type')->firstOrFail();
    $floors = $intake->externalFacts()->where('fact_key', 'floor_count')->firstOrFail();

    // Nokhoogte minus maaiveld — de hoogte die ladder of steiger bepaalt.
    expect($height->value)->toBe(['number' => 29.1, 'unit' => 'm'])
        ->and($height->source)->toBe('3DBAG (TU Delft)')
        ->and($height->confidence)->toBe('high')
        ->and($height->source_url)->toContain('NL.IMBAG.Pand.0363100012185508')
        ->and($roof->value['label'])->toBe('Schuin dak')
        ->and($floors->value)->toBe(['number' => 4]);
});

test('an unreliable 3DBAG reconstruction is flagged instead of trusted', function () {
    config()->set('services.threedbag.base_url', 'https://api.3dbag.test');
    fakeSuccessfulPdok(threeDBagFeature: threeDBagFeature(reliable: false));
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Onzeker Pand',
        'customer_email' => 'onzeker@example.com',
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012LG',
        'address_city' => 'Amsterdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ]);

    $intake = Intake::query()->where('customer_email', 'onzeker@example.com')->firstOrFail();

    expect($intake->externalFacts()->where('fact_key', 'building_height_m')->firstOrFail()->confidence)->toBe('low');

    $presented = app(ExternalFactPresenter::class)->present($intake->fresh());

    expect($presented['uncertainties'])->toContain(
        'De 3D-reconstructie van dit pand is door 3DBAG als mogelijk onjuist gemarkeerd; gebruik hoogte en dakvorm alleen als indicatie.'
    );
});

test('an unusable roof type is left out rather than shown as unknown', function () {
    config()->set('services.threedbag.base_url', 'https://api.3dbag.test');
    fakeSuccessfulPdok(threeDBagFeature: threeDBagFeature(roofType: 'no planes'));
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Geen Dakvlak',
        'customer_email' => 'geendak@example.com',
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012LG',
        'address_city' => 'Amsterdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ]);

    $intake = Intake::query()->where('customer_email', 'geendak@example.com')->firstOrFail();

    expect($intake->externalFacts()->where('fact_key', 'roof_type')->exists())->toBeFalse()
        ->and($intake->externalFacts()->where('fact_key', 'building_height_m')->exists())->toBeTrue();
});

test('a 3DBAG outage never blocks the intake or the rest of the enrichment', function () {
    config()->set('services.threedbag.base_url', 'https://api.3dbag.test');
    fakeSuccessfulPdok(threeDBagStatus: 503);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Storing Klant',
        'customer_email' => 'storing3d@example.com',
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012LG',
        'address_city' => 'Amsterdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ]);

    $intake = Intake::query()->where('customer_email', 'storing3d@example.com')->firstOrFail();

    expect($intake->externalFacts()->where('fact_key', 'building_height_m')->exists())->toBeFalse()
        // De BAG-verrijking zelf moet gewoon geslaagd zijn.
        ->and($intake->externalFacts()->where('fact_key', 'building_year')->exists())->toBeTrue()
        ->and($intake->answers()->where('question_key', 'build_year')->exists())->toBeTrue();
});

/** @return array<string, mixed> */
function kadasterAddress(
    array $pandIds = ['0363100012185508'],
    array $bouwjaar = ['1890'],
    int $oppervlakte = 16100,
    array $gebruiksdoelen = ['bijeenkomstfunctie', 'logiesfunctie'],
): array {
    return [
        '_embedded' => [
            'adressen' => [[
                'openbareRuimteNaam' => 'Damrak',
                'huisnummer' => 1,
                'postcode' => '1012LG',
                'woonplaatsNaam' => 'Amsterdam',
                'adresseerbaarObjectIdentificatie' => '0363010012111931',
                'pandIdentificaties' => $pandIds,
                'oppervlakte' => $oppervlakte,
                'gebruiksdoelen' => $gebruiksdoelen,
                'oorspronkelijkBouwjaar' => $bouwjaar,
            ]],
        ],
    ];
}

function enableKadasterBag(): void
{
    config()->set('services.bag_api.enabled', true);
    config()->set('services.bag_api.key', 'test-key');
    config()->set('services.bag_api.base_url', 'https://api.bag.kadaster.test/v2');
}

function storeIntakeViaInstaller(string $email): Intake
{
    $user = User::factory()->create();

    test()->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Kadaster Klant',
        'customer_email' => $email,
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012LG',
        'address_city' => 'Amsterdam',
        'address_lookup_id' => 'adr-8f4d573be765b4c80dd635ba73747903',
    ]);

    return Intake::query()->where('customer_email', $email)->firstOrFail();
}

test('Kadaster supplies the BAG attributes and is queried with an exact match and api key', function () {
    enableKadasterBag();

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'kadaster.test')) {
            return Http::response(kadasterAddress());
        }

        if (str_contains($request->url(), '/lookup')) {
            return Http::response(['response' => ['docs' => [pdokAddressDocument()]]]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response([
                'features' => [[
                    'properties' => ['identificatie' => '0363010012111931'],
                    'geometry' => ['type' => 'Point', 'coordinates' => [4.89803846, 52.37714446]],
                ]],
            ]);
        }

        if (str_contains($request->url(), '/aerial')) {
            return Http::response(fakeAerialJpeg(), 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response([], 404);
    });

    $intake = storeIntakeViaInstaller('kadaster@example.com');
    $facts = $intake->externalFacts()->pluck('value', 'fact_key');

    expect($intake->answers()->where('question_key', 'build_year')->firstOrFail()->value)->toBe(['number' => 1890])
        ->and($facts['building_year'])->toBe(['number' => 1890])
        ->and($facts['floor_area_m2'])->toBe(['number' => 16100, 'unit' => 'm²'])
        ->and($facts['usage_purposes'])->toBe(['values' => ['bijeenkomstfunctie', 'logiesfunctie']])
        // Coördinaten blijven van PDOK komen; Kadaster levert RD, het dossier wil WGS84.
        ->and($facts['location']['latitude'])->toBe(52.37714446);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/adressenuitgebreid')
        && $request->hasHeader('X-Api-Key', 'test-key')
        && $request['postcode'] === '1012LG'
        && (int) $request['huisnummer'] === 1
        && $request['exacteMatch'] === 'true');
});

test('a Kadaster outage silently falls back to the open PDOK route', function () {
    enableKadasterBag();

    fakeSuccessfulPdok();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'kadaster.test')) {
            return Http::response([], 500);
        }

        return null;
    });
    fakeSuccessfulPdok();

    $intake = storeIntakeViaInstaller('kadasterstoring@example.com');

    // PDOK levert exact hetzelfde bouwjaar, dus de aanvrager merkt niets van de storing.
    expect($intake->answers()->where('question_key', 'build_year')->firstOrFail()->value)->toBe(['number' => 1890])
        ->and($intake->externalFacts()->where('fact_key', 'building_year')->exists())->toBeTrue();
});

test('an ambiguous Kadaster answer is refused rather than guessed', function () {
    enableKadasterBag();

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'kadaster.test')) {
            // Twee treffers bij exacteMatch betekent dat het huisnummer niet volledig is.
            return Http::response([
                '_embedded' => [
                    'adressen' => [
                        kadasterAddress()['_embedded']['adressen'][0],
                        kadasterAddress()['_embedded']['adressen'][0],
                    ],
                ],
            ]);
        }

        return null;
    });
    fakeSuccessfulPdok();

    $intake = storeIntakeViaInstaller('kadasterdubbel@example.com');

    expect($intake->externalFacts()->where('fact_key', 'building_year')->exists())->toBeTrue();
});

test('a build year that differs per pand is left to the applicant', function () {
    enableKadasterBag();

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'kadaster.test')) {
            return Http::response(kadasterAddress(
                pandIds: ['0363100012185508', '0363100012185509'],
                bouwjaar: ['1890', '1975'],
            ));
        }

        if (str_contains($request->url(), '/lookup')) {
            return Http::response(['response' => ['docs' => [pdokAddressDocument()]]]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response([
                'features' => [[
                    'properties' => ['identificatie' => '0363010012111931'],
                    'geometry' => ['type' => 'Point', 'coordinates' => [4.89803846, 52.37714446]],
                ]],
            ]);
        }

        return Http::response([], 404);
    });

    $intake = storeIntakeViaInstaller('kadasterjaren@example.com');

    // Twee panden met verschillende bouwjaren: geen voorzet, en de bouwjaarvraag blijft staan.
    expect($intake->answers()->where('question_key', 'build_year')->exists())->toBeFalse()
        ->and($intake->externalFacts()->where('fact_key', 'building_match')->firstOrFail()->value['status'])
        ->toBe('ambiguous');
});

test('without a key the Kadaster API is never called', function () {
    config()->set('services.bag_api.enabled', true);
    config()->set('services.bag_api.key', '');

    fakeSuccessfulPdok();

    $intake = storeIntakeViaInstaller('geenkey@example.com');

    expect($intake->externalFacts()->where('fact_key', 'building_year')->exists())->toBeTrue();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'kadaster'));
});

test('a messy hand-typed address is rescued by Kadaster and rewritten to the BAG spelling', function () {
    enableKadasterBag();

    // Precies het productiegeval: huisnummer dubbel ingetypt. De Locatieserver vindt het
    // adres wel, maar matchesIntake() wijst het af — waarna vroeger de héle verrijking
    // leeg bleef en het dossier 'Nog geen externe gegevens beschikbaar' toonde.
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'kadaster.test')) {
            return Http::response([
                '_embedded' => [
                    'adressen' => [[
                        'openbareRuimteNaam' => 'Bernadottelaan',
                        'huisnummer' => 273,
                        'postcode' => '2037GR',
                        'woonplaatsNaam' => 'Haarlem',
                        'adresseerbaarObjectIdentificatie' => '0392010000123456',
                        'pandIdentificaties' => ['0392100000123456'],
                        'oppervlakte' => 118,
                        'gebruiksdoelen' => ['woonfunctie'],
                        'oorspronkelijkBouwjaar' => ['1962'],
                    ]],
                ],
            ]);
        }

        if (str_contains($request->url(), '/free')) {
            return Http::response(['response' => ['docs' => [[
                ...pdokAddressDocument(),
                'straatnaam' => 'Bernadottelaan',
                'huisnummer' => 273,
                'postcode' => '2037GR',
                'woonplaatsnaam' => 'Haarlem',
                'weergavenaam' => 'Bernadottelaan 273, 2037GR Haarlem',
            ]]]]);
        }

        if (str_contains($request->url(), '/collections/verblijfsobject/items')) {
            return Http::response([
                'features' => [[
                    'properties' => ['identificatie' => '0392010000123456'],
                    'geometry' => ['type' => 'Point', 'coordinates' => [4.6462, 52.3874]],
                ]],
            ]);
        }

        if (str_contains($request->url(), '/aerial')) {
            return Http::response(fakeAerialJpeg(), 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Rommelig Adres',
        'customer_email' => 'rommelig@example.com',
        'address_line' => 'Bernadottelaan, 273, 273',
        'address_postal_code' => '2037GR',
        'address_city' => 'Haarlem',
    ]);

    $intake = Intake::query()->where('customer_email', 'rommelig@example.com')->firstOrFail();
    $facts = $intake->externalFacts()->pluck('value', 'fact_key');

    expect($intake->address_line)->toBe('Bernadottelaan 273')
        ->and($facts['building_year'])->toBe(['number' => 1962])
        ->and($facts['floor_area_m2'])->toBe(['number' => 118, 'unit' => 'm²'])
        ->and($intake->answers()->where('question_key', 'build_year')->firstOrFail()->value)->toBe(['number' => 1962])
        // Een woonfunctie mag het bouwtype juist níét invullen.
        ->and($intake->answers()->where('question_key', 'building_type')->exists())->toBeFalse();
});

test('without Kadaster a messy address still leaves an explicit uncertainty', function () {
    config()->set('services.bag_api.enabled', false);

    fakeSuccessfulPdok();

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Rommelig Zonder Kadaster',
        'customer_email' => 'rommelig2@example.com',
        'address_line' => 'Damrak, 1, 1',
        'address_postal_code' => '1012LG',
        'address_city' => 'Amsterdam',
    ]);

    $intake = Intake::query()->where('customer_email', 'rommelig2@example.com')->firstOrFail();

    expect($intake->externalFacts()->where('fact_key', 'address_verification')->firstOrFail()->value)
        ->toBe(['status' => 'not_found']);
});
