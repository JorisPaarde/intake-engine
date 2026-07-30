<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.pdok.enabled' => true]);
});

test('installer looks up an address by normalized postcode and house number', function () {
    Http::fake([
        'https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest*' => Http::response([
            'response' => [
                'docs' => [
                    [
                        'id' => 'adr-1234567890abcdef1234567890abcdef',
                        'weergavenaam' => 'Teststraat 10A, 1234 AB Testdam',
                        'type' => 'adres',
                        'straatnaam' => 'Teststraat',
                        'huisnummer' => 10,
                        'huisletter' => 'A',
                        'huisnummertoevoeging' => null,
                        'postcode' => '1234AB',
                        'woonplaatsnaam' => 'Testdam',
                    ],
                    [
                        'id' => 'adr-fedcba0987654321fedcba0987654321',
                        'weergavenaam' => 'Teststraat 10B, 1234 AB Testdam',
                        'type' => 'adres',
                        'straatnaam' => 'Teststraat',
                        'huisnummer' => 10,
                        'huisletter' => 'B',
                        'huisnummertoevoeging' => null,
                        'postcode' => '1234AB',
                        'woonplaatsnaam' => 'Testdam',
                    ],
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('address-suggestions', [
            'postal_code' => '1234 ab',
            'house_number' => '10',
            'house_number_addition' => 'a',
        ]))
        ->assertOk()
        ->assertExactJson([
            'data' => [[
                'id' => 'adr-1234567890abcdef1234567890abcdef',
                'label' => 'Teststraat 10A, 1234 AB Testdam',
                'address_line' => 'Teststraat 10-A',
                'postal_code' => '1234AB',
                'house_number' => 10,
                'house_number_addition' => 'A',
                'city' => 'Testdam',
            ]],
        ]);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/suggest')
            && $request['q'] === '1234 AB 10 A'
            && $request['fq'] === 'type:adres'
            && $request['rows'] === 50;
    });
});

test('lookup without an addition keeps all exact additions selectable', function () {
    Http::fake([
        'https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest*' => Http::response([
            'response' => [
                'docs' => array_map(
                    fn (string $letter): array => [
                        'id' => 'adr-'.str_repeat(strtolower($letter), 32),
                        'weergavenaam' => "Teststraat 10{$letter}, 1234 AB Testdam",
                        'straatnaam' => 'Teststraat',
                        'huisnummer' => 10,
                        'huisletter' => $letter,
                        'postcode' => '1234AB',
                        'woonplaatsnaam' => 'Testdam',
                    ],
                    ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'],
                ),
            ],
        ]),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('address-suggestions', [
            'postal_code' => '1234 AB',
            'house_number' => 10,
        ]))
        ->assertOk()
        ->assertJsonCount(8, 'data')
        ->assertJsonPath('data.0.address_line', 'Teststraat 10-A')
        ->assertJsonPath('data.0.house_number_addition', 'A')
        ->assertJsonPath('data.7.address_line', 'Teststraat 10-H');
});

test('postcode lookup keeps meaningful addition separators distinct', function () {
    Http::fake([
        'https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest*' => Http::response([
            'response' => [
                'docs' => [[
                    'id' => 'adr-cccccccccccccccccccccccccccccccc',
                    'weergavenaam' => 'Teststraat 10 A-B, 1234 AB Testdam',
                    'straatnaam' => 'Teststraat',
                    'huisnummer' => 10,
                    'huisletter' => 'A',
                    'huisnummertoevoeging' => 'B',
                    'postcode' => '1234AB',
                    'woonplaatsnaam' => 'Testdam',
                ]],
            ],
        ]),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('address-suggestions', [
            'postal_code' => '1234 AB',
            'house_number' => 10,
            'house_number_addition' => 'AB',
        ]))
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('postcode lookup excludes nearby addresses with another house number', function () {
    Http::fake([
        'https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest*' => Http::response([
            'response' => [
                'docs' => [
                    [
                        'id' => 'adr-11111111111111111111111111111111',
                        'weergavenaam' => 'Teststraat 10, 1234 AB Testdam',
                        'straatnaam' => 'Teststraat',
                        'huisnummer' => 10,
                        'postcode' => '1234AB',
                        'woonplaatsnaam' => 'Testdam',
                    ],
                    [
                        'id' => 'adr-22222222222222222222222222222222',
                        'weergavenaam' => 'Teststraat 12, 1234 AB Testdam',
                        'straatnaam' => 'Teststraat',
                        'huisnummer' => 12,
                        'postcode' => '1234AB',
                        'woonplaatsnaam' => 'Testdam',
                    ],
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('address-suggestions', [
            'postal_code' => '1234 AB',
            'house_number' => 10,
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.address_line', 'Teststraat 10');
});

test('invalid postcode lookup is rejected before calling PDOK', function () {
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('address-suggestions', [
            'postal_code' => '12AB',
            'house_number' => 0,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['postal_code', 'house_number']);

    $this->actingAs($user)
        ->getJson(route('address-suggestions', [
            'postal_code' => '0000 AB',
            'house_number' => 10,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['postal_code']);

    Http::assertNothingSent();
});

test('PDOK outage returns no suggestions so manual entry can continue', function () {
    Http::fake([
        'https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest*' => Http::response([], 503),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('address-suggestions', [
            'postal_code' => '1234 AB',
            'house_number' => 10,
        ]))
        ->assertOk()
        ->assertExactJson(['data' => []]);
});
