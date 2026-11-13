<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\DeriveIntentFromRequestJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\EmbedFormToken;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
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
    config([
        'ai.request_prefill.sync_on_create' => false,
        'ai.request_prefill.wizard_wait_seconds' => 20,
        'ai.text_inference.enabled' => false,
        'ai.provider' => 'fake',
        'services.pdok.enabled' => true,
        'services.pdok.search_base_url' => 'https://api.pdok.test/search',
        'services.pdok.bag_base_url' => 'https://api.pdok.test/bag',
        'services.pdok.timeout_seconds' => 1,
        'services.pdok.aerial_enabled' => false,
        'services.bag_api.enabled' => false,
    ]);

    // Embed lost straat/plaats via PDOK suggest vóór create (BL-154).
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/suggest') || str_contains($request->url(), '/lookup')) {
            return Http::response([
                'response' => ['docs' => [[
                    'id' => 'adr-embed-ux-1234ab-12',
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
                        'pand.href' => ['https://api.pdok.test/bag/collections/pand/items/pand-ux'],
                    ],
                    'geometry' => ['type' => 'Point', 'coordinates' => [4.9, 52.3]],
                ]],
            ]);
        }

        if (str_contains($request->url(), '/collections/pand/items/pand-ux')) {
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
 * @return array{company: Company, user: User, key: string}
 */
function embedUxCompany(): array
{
    $company = Company::factory()->withEmbedKey('embed-ux-key-cccccccccccccccc')->create([
        'embed_enabled' => true,
        'embed_allowed_origins' => ['www.example.nl'],
        'embed_daily_limit' => 20,
        'name' => 'UX Koeling',
    ]);
    $user = User::factory()->for($company)->create();

    return [
        'company' => $company->fresh(),
        'user' => $user,
        'key' => (string) $company->embed_public_key,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function embedUxPayload(string $key, array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'UX Embed Klant',
        'customer_email' => 'embed.ux@example.com',
        'customer_phone' => '0612345678',
        'address_postal_code' => '1234AB',
        'address_house_number' => '12',
        'address_house_number_addition' => null,
        'privacy_consent' => '1',
        'website' => '',
        'address_confirmed' => '1',
        EmbedFormToken::FIELD => app(EmbedFormToken::class)->issue($key),
    ], $overrides);
}

function postEmbedUx(string $key, array $payload = [])
{
    return test()->withHeaders([
        'Origin' => 'https://www.example.nl',
        'Referer' => 'https://www.example.nl/airco',
    ])->post(route('embed.store', ['public_key' => $key]), embedUxPayload($key, $payload));
}

test('embed Volgende op request_reason dispatcht async en toont wachtscherm zonder sync DeriveIntent', function () {
    Mail::fake();
    Queue::fake();
    $ctx = embedUxCompany();

    postEmbedUx($ctx['key'])->assertStatus(303);
    $intake = Intake::query()->where('customer_email', 'embed.ux@example.com')->firstOrFail();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.request_reason.text', '3 slaapkamers, verwarmen en koelen, 3 bij 5 meter')
        ->assertSet('waitingForPrefill', false)
        ->call('next')
        ->assertSet('waitingForPrefill', true)
        ->assertSet('resumeAfterEmbedPrefill', true)
        ->assertSee('Even geduld, we zetten je vragen klaar');

    Queue::assertPushed(DeriveIntentFromRequestJob::class, 1);

    // Geen synchrone catalogus-run in de Livewire-request: alleen de queue-placeholder.
    $runs = AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('type', AiRunType::RequestIntent)
        ->get();
    expect($runs)->toHaveCount(1)
        ->and($runs->first()?->status)->toBe(AiRunStatus::Pending)
        ->and($runs->first()?->provider)->toBe(DeriveIntentFromRequestJob::PLACEHOLDER_PROVIDER);

    // Volgende tijdens wacht is een no-op.
    $component->call('next')->assertSet('waitingForPrefill', true);
});

test('embed prefill-wacht landt na afronden op known-summary of volgende stap', function () {
    Mail::fake();
    Queue::fake();
    $ctx = embedUxCompany();

    postEmbedUx($ctx['key'], [
        'customer_email' => 'embed.wait.resume@example.com',
    ])->assertStatus(303);

    $intake = Intake::query()->where('customer_email', 'embed.wait.resume@example.com')->firstOrFail();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.request_reason.text', 'Airco in 3 slaapkamers van 3 bij 5 meter, koelen en verwarmen')
        ->call('next')
        ->assertSet('waitingForPrefill', true);

    // Simuleer klaar: placeholder → succeeded (queue worker afgerond).
    AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('type', AiRunType::RequestIntent)
        ->where('status', AiRunStatus::Pending)
        ->update([
            'status' => AiRunStatus::Succeeded,
            'finished_at' => now(),
        ]);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'indoor_unit_count',
        null,
        ['number' => 3],
        PrefillSources::REQUEST_TEXT,
        factProvenance: 'stated',
        factConfidence: 100,
        factEvidence: '3 slaapkamers',
        factSource: 'klantantwoord',
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'cooling_heating',
        null,
        ['value' => 'both'],
        PrefillSources::REQUEST_TEXT,
        factProvenance: 'stated',
        factConfidence: 100,
        factEvidence: 'koelen en verwarmen',
        factSource: 'klantantwoord',
    );
    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        app(SaveIntakeAnswer::class)->handle(
            $intake,
            'room_type',
            $room,
            ['value' => 'bedroom'],
            PrefillSources::REQUEST_TEXT,
            factProvenance: 'stated',
            factConfidence: 100,
            factEvidence: 'slaapkamers',
            factSource: 'klantantwoord',
        );
    }

    $component->call('pollPrefillWait')
        ->assertSet('waitingForPrefill', false)
        ->assertSet('resumeAfterEmbedPrefill', false)
        ->assertDontSee('Even geduld, we zetten je vragen klaar');

    $activeKey = (string) $component->get('activeStepKey');
    expect($activeKey)->not->toBe('')
        ->and($activeKey)->not->toContain('request_reason');
});

test('ruimtelabels tonen Slaapkamer N van M op elke ruimtestap', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'source' => IntakeSource::Embed,
        'customer_access_enabled' => true,
    ]);

    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 3]);
    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $save->handle($intake, 'room_type', $room, ['value' => 'bedroom']);
    }
    app(DossierManager::class)->initialize($intake->fresh());

    $intake = $intake->fresh(['answers', 'aircoRooms', 'templateVersion.sections.questions.options']);
    expect($intake->aircoRooms)->toHaveCount(3);

    $steps = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);

    $room2Photo = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'room_photos'
            && ($step['section_instance_key'] ?? null) === 'room-2',
    );

    expect($room2Photo)->not->toBeNull()
        ->and($room2Photo['section_title'])->toBe('Kamer 2 van 3 (slaapkamer)')
        ->and($room2Photo['title'])->toBe('Foto’s van de ruimte')
        ->and($room2Photo['title'])->not->toContain('Kamer 2 van 3');

    $room2Dims = collect($steps)->first(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'room_dimensions'
            && ($step['section_instance_key'] ?? null) === 'room-2',
    );
    // Zonder maten blijft het scherm bestaan; label alleen in section_title.
    if ($room2Dims !== null) {
        expect($room2Dims['section_title'])->toBe('Kamer 2 van 3 (slaapkamer)')
            ->and($room2Dims['title'])->not->toContain('Kamer 2 van 3');
    }
});

test('vooraf opgegeven L×B (request_text stated) slaat matenscherm per ruimte over', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'source' => IntakeSource::Embed,
        'customer_access_enabled' => true,
    ]);

    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 3], PrefillSources::REQUEST_TEXT, factProvenance: 'stated', factConfidence: 100, factEvidence: '3 slaapkamers', factSource: 'klantantwoord');

    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $save->handle($intake, 'room_type', $room, ['value' => 'bedroom'], PrefillSources::REQUEST_TEXT, factProvenance: 'stated', factConfidence: 100, factEvidence: 'slaapkamers', factSource: 'klantantwoord');
        $save->handle($intake, 'room_length_m', $room, ['number' => 3], PrefillSources::REQUEST_TEXT, factProvenance: 'stated', factConfidence: 100, factEvidence: '3 bij 5 meter', factSource: 'klantantwoord');
        $save->handle($intake, 'room_width_m', $room, ['number' => 5], PrefillSources::REQUEST_TEXT, factProvenance: 'stated', factConfidence: 100, factEvidence: '3 bij 5 meter', factSource: 'klantantwoord');
    }

    // derived_lxw oppervlak zoals PrefillAnswersFromKnownContext zou zetten
    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $save->handle($intake, 'room_area_m2', $room, ['number' => 15], PrefillSources::DERIVED_LXW, factConfidence: 100, factSource: 'afgeleid');
    }

    $intake = $intake->fresh(['answers', 'aircoRooms', 'templateVersion.sections.questions.options']);
    $steps = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);

    $dimensionSteps = collect($steps)->filter(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'room_dimensions',
    )->values();

    expect($dimensionSteps)->toHaveCount(0);

    // Zonder prefill blijft cooling_heating één household-vraag (niet per ruimte).
    $cooling = collect($steps)->filter(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'cooling_heating',
    )->values();
    expect($cooling)->toHaveCount(1)
        ->and($cooling->first()['section_instance_key'])->toBeNull();
});

test('wizard next-button toont wire:loading Even geduld markup', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'source' => IntakeSource::Installer,
        'customer_access_enabled' => true,
    ]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSee('data-testid="next-button"', false)
        ->assertSee('data-testid="next-loading"', false)
        ->assertSee('Even geduld…', false)
        ->assertSee('wire:loading.attr="disabled"', false);
});
