<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\EmbedFormToken;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\CustomerConsentPresenter;
use App\Domains\Intake\Support\MustAcceptQuestions;
use App\Enums\IntakeSource;
use App\Livewire\Customer\IntakeWizard;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(IntakeTemplateSeeder::class);
    config([
        'ai.request_prefill.sync_on_create' => false,
        'ai.text_inference.enabled' => false,
        'ai.provider' => 'fake',
        'services.pdok.enabled' => true,
        'services.pdok.search_base_url' => 'https://api.pdok.test/search',
        'services.pdok.bag_base_url' => 'https://api.pdok.test/bag',
        'services.pdok.timeout_seconds' => 1,
        'services.pdok.aerial_enabled' => false,
        'services.bag_api.enabled' => false,
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/suggest') || str_contains($request->url(), '/lookup')) {
            return Http::response([
                'response' => ['docs' => [[
                    'id' => 'adr-embed-consent-1234ab-12',
                    'weergavenaam' => 'Voorbeeldstraat 12, 1234AB Voorbeeldstad',
                    'straatnaam' => 'Voorbeeldstraat',
                    'huisnummer' => 12,
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
                        'oppervlakte' => 100,
                        'gebruiksdoel' => 'woonfunctie',
                        'pand.href' => ['https://api.pdok.test/bag/collections/pand/items/pand-consent'],
                    ],
                    'geometry' => ['type' => 'Point', 'coordinates' => [4.9, 52.3]],
                ]],
            ]);
        }

        if (str_contains($request->url(), '/collections/pand/items/pand-consent')) {
            return Http::response([
                'properties' => [
                    'identificatie' => '0363100012111931',
                    'bouwjaar' => 1990,
                ],
            ]);
        }

        return Http::response([], 404);
    });
});

/**
 * @return array{company: Company, key: string}
 */
function embedConsentCompany(): array
{
    $company = Company::factory()->withEmbedKey('embed-consent-key-bbbbbbbbbbbb')->create([
        'embed_enabled' => true,
        'embed_allowed_origins' => ['www.example.nl'],
        'embed_daily_limit' => 20,
        'name' => 'Consent Koeling',
    ]);
    User::factory()->for($company)->create();

    return [
        'company' => $company->fresh(),
        'key' => (string) $company->embed_public_key,
    ];
}

test('embed-toestemming wordt opgeslagen en privacy_consent verdwijnt uit de wizard', function () {
    Mail::fake();
    $ctx = embedConsentCompany();

    $response = $this->withHeaders([
        'Origin' => 'https://www.example.nl',
        'Referer' => 'https://www.example.nl/airco',
    ])->post(route('embed.store', ['public_key' => $ctx['key']]), [
        'customer_name' => 'Consent Klant',
        'customer_email' => 'embed.consent@example.com',
        'customer_phone' => '0612345678',
        'address_postal_code' => '1234AB',
        'address_house_number' => 12,
        'privacy_consent' => '1',
        'website' => '',
        EmbedFormToken::FIELD => app(EmbedFormToken::class)->issue($ctx['key']),
    ]);

    $response->assertStatus(303);

    $intake = Intake::query()->where('customer_email', 'embed.consent@example.com')->firstOrFail();
    expect($intake->source)->toBe(IntakeSource::Embed);

    $privacy = $intake->answers()->where('question_key', 'privacy_consent')->first();
    expect($privacy)->not->toBeNull()
        ->and(MustAcceptQuestions::isAccepted(
            is_array($privacy?->value) ? $privacy->value : null,
            $privacy?->prefill_source,
        ))->toBeTrue();

    $consent = app(CustomerConsentPresenter::class)->present($intake->fresh('answers'));
    expect($consent['given'])->toBeTrue()
        ->and($consent['asked'])->toBeTrue();

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh(['answers', 'templateVersion.sections.questions.options', 'templateVersion.sections.questions.rules']),
        $intake->templateVersion,
    );

    $privacySteps = collect($steps)->filter(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'privacy_consent',
    );
    $truthSteps = collect($steps)->filter(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'truth_confirmation',
    );

    expect($privacySteps)->toHaveCount(0)
        ->and($truthSteps)->toHaveCount(1);

    // Teller: privacy ontbreekt vanaf het begin — geen sprong N/N → N+1/N+1 aan het eind.
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $wizardSteps = $component->viewData('steps');
    expect(collect($wizardSteps)->firstWhere('question_key', 'privacy_consent'))->toBeNull();

    $truthIndex = collect($wizardSteps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'truth_confirmation',
    );
    expect($truthIndex)->toBeInt();

    $component
        ->set('stepIndex', $truthIndex)
        ->set('activeStepKey', $wizardSteps[$truthIndex]['key']);

    $number = (int) $component->viewData('stepDisplayNumber');
    $total = (int) $component->viewData('stepDisplayTotal');

    expect($total)->toBe(count($wizardSteps))
        ->and($number)->toBeLessThanOrEqual($total)
        ->and($component->html())->not->toContain('privacy_consent');
});

test('pipe_visibility toont klantvriendelijke uitleg en labels', function () {
    Mail::fake();
    $ctx = embedConsentCompany();

    $this->withHeaders([
        'Origin' => 'https://www.example.nl',
        'Referer' => 'https://www.example.nl/airco',
    ])->post(route('embed.store', ['public_key' => $ctx['key']]), [
        'customer_name' => 'Pipe Klant',
        'customer_email' => 'embed.pipe@example.com',
        'address_postal_code' => '1234AB',
        'address_house_number' => 12,
        'privacy_consent' => '1',
        'website' => '',
        EmbedFormToken::FIELD => app(EmbedFormToken::class)->issue($ctx['key']),
    ])->assertStatus(303);

    $intake = Intake::query()->where('customer_email', 'embed.pipe@example.com')->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh(['answers', 'templateVersion.sections.questions.options', 'templateVersion.sections.questions.rules']),
        $intake->templateVersion,
    );
    $pipe = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'pipe_visibility',
    );
    expect($pipe)->not->toBeNull();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $pipe['key'])
        ->assertSee('Liever zichtwerk of weggewerkt?', false)
        ->assertSee('nette goot', false)
        ->assertSee('vaak duurder', false)
        ->assertSee('Zichtwerk:', false)
        ->assertSee('Weggewerkt:', false);
});
