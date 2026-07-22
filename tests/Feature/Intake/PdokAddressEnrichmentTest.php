<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\GenerateIntakePdf;
use App\Domains\Intake\Actions\HardDeleteIntake;
use App\Domains\Intake\Actions\StartDemoIntake;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
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

function fakeSuccessfulPdok(int $aerialStatus = 200): void
{
    $aerialJpeg = fakeAerialJpeg();

    Http::fake(function (Request $request) use ($aerialJpeg, $aerialStatus) {
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
        ->and($intake->templateVersion->version)->toBe(7)
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
