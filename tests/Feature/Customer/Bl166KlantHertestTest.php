<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\EpOnlineService;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\CustomerAnswerBlocks;
use App\Domains\Intake\Support\CustomerCompletionSummary;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\SharedRoomAnswers;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function bl166Intake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'bl166@example.com',
        'customer_name' => 'BL166 Tester',
        'address_line' => 'Grote Markt 2',
        'address_postal_code' => '2011RD',
        'address_house_number' => 2,
        'address_city' => 'Haarlem',
    ], $overrides));
}

test('bedankscherm toont termijn en overzicht zonder interne keys', function () {
    config(['intake.customer_completion.installer_response_within' => 'binnen 3 werkdagen']);

    $intake = bl166Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'cooling_heating', null, ['value' => 'cooling']);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 2]);
    app(SaveIntakeAnswer::class)->handle($intake, 'ownership', null, ['value' => 'rented']);
    app(SaveIntakeAnswer::class)->handle($intake, 'landlord_permission', null, ['value' => 'not_asked']);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'room_photos',
        'section_instance_key' => 'room-1',
        'disk' => 'local',
        'path' => 'tests/bl166-a.jpg',
        'original_filename' => 'a.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'checksum' => 'bl166-a',
        'sort_order' => 1,
    ]);

    $summary = CustomerCompletionSummary::forIntake($intake->fresh(['answers', 'uploads', 'aircoRooms', 'templateVersion.sections.questions.options']) ?? $intake);

    expect($summary['response_within'])->toBe('binnen 3 werkdagen')
        ->and($summary['address'])->toContain('Grote Markt')
        ->and($summary['room_count'])->toBeGreaterThanOrEqual(1)
        ->and($summary['photo_count'])->toBe(1)
        ->and(collect($summary['highlights'])->pluck('label')->all())->toContain('Koop of huur', 'Toestemming verhuurder')
        ->and(collect($summary['highlights'])->firstWhere('label', 'Toestemming verhuurder')['value'] ?? null)->toBe('Nog niet gevraagd')
        ->and(json_encode($summary))->not->toContain('outdoor_mount_type')
        ->and(json_encode($summary))->not->toContain('cooling_heating');

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('completed', true)
        ->assertSeeHtml('data-testid="thank-you-timeline"')
        ->assertSeeHtml('data-testid="thank-you-summary"')
        ->assertSeeHtml('data-testid="thank-you-check"')
        ->assertSee('Je aanvraag is verstuurd')
        ->assertSee('binnen 3 werkdagen')
        ->assertSee('Dit heb je doorgegeven')
        ->assertSee('Grote Markt')
        ->assertSee('Toestemming verhuurder')
        ->assertDontSee('Jouw deel is compleet')
        ->assertDontSeeHtml('data-testid="wizard-progress"')
        ->assertDontSee('outdoor_mount_type');
});

test('bedrijfspand toont pandgegevens en melding; energielabel alleen uit EP-Online', function () {
    $intake = bl166Intake();

    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'usage_purposes',
        'label' => 'Gebruiksdoel',
        'value' => ['values' => ['bijeenkomstfunctie', 'winkelfunctie']],
        'source' => 'PDOK / BAG',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);
    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'building_year',
        'label' => 'Bouwjaar',
        'value' => ['number' => 1900],
        'source' => 'PDOK / BAG',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);
    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'energy_label',
        'label' => 'Energielabel',
        'value' => ['value' => 'C'],
        'source' => 'demo-label',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    app(SaveIntakeAnswer::class)->handle($intake, 'build_year', null, ['number' => 1900], 'pdok');
    app(SaveIntakeAnswer::class)->handle($intake, 'building_type', null, ['value' => 'commercial'], 'pdok');

    $fresh = $intake->fresh(['answers', 'externalFacts', 'templateVersion.sections.questions.options']) ?? $intake;

    expect($fresh->isNonResidentialProperty())->toBeTrue()
        ->and($fresh->knownPropertyFactsNoun())->toBe('pandgegevens')
        ->and($fresh->knownPropertyFactsIntro())->toContain('pandgegevens')
        ->and($fresh->nonResidentialCustomerNote())->toContain('geen woonfunctie')
        ->and(collect($fresh->knownHousingFactLines())->pluck('label')->all())->toContain('Pandtype')
        ->and(collect($fresh->knownHousingFactLines())->pluck('label')->all())->not->toContain('Energielabel')
        ->and(collect($fresh->knownHousingFactLines())->pluck('label')->all())->not->toContain('Woningtype');

    IntakeExternalFact::query()->where('intake_id', $intake->id)->where('fact_key', 'energy_label')->delete();
    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'energy_label',
        'label' => 'Energielabel',
        'value' => ['value' => 'A'],
        'source' => EpOnlineService::sourceName(),
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    $withLabel = $intake->fresh(['answers', 'externalFacts', 'templateVersion.sections.questions.options']) ?? $intake;
    expect(collect($withLabel->knownHousingFactLines())->firstWhere('label', 'Energielabel')['value'] ?? null)->toBe('A');

    // BL-169: klant ziet geen woonfunctie-melding meer; pandregel + installateur behouden de info.
    $html = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])->html();
    expect($html)->toContain('data-testid="known-property-intro"')
        ->and($html)->toContain('pandtype')
        ->and($html)->not->toContain('data-testid="non-residential-note"')
        ->and($html)->not->toContain('geen woonfunctie')
        ->and($html)->not->toContain('bekende woninggegevens');
});

test('v32 teksten: geluid-help, slaapkamer-voorbeeld, één merkuitleg, geen room_outlet share', function () {
    $intake = bl166Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    expect($version->version)->toBeGreaterThanOrEqual(32);

    $noise = $version->sections->flatMap(fn ($s) => $s->questions)->firstWhere('key', 'noise_sensitive');
    expect($noise?->help_text)->toContain('buren')
        ->and($noise?->help_text)->toContain('slaapkamer');

    $brand = $version->sections->flatMap(fn ($s) => $s->questions)->firstWhere('key', 'brand_preference');
    expect($brand?->help_text)->toBeNull();

    $landlord = $version->sections->flatMap(fn ($s) => $s->questions)->firstWhere('key', 'landlord_permission');
    expect($landlord)->not->toBeNull()
        ->and($landlord->is_required)->toBeFalse()
        ->and($landlord->rules->first()?->source_question_key)->toBe('ownership');

    expect(SharedRoomAnswers::shareableQuestionKeys())->not->toContain('room_outlet_status');

    $steps = app(IntakeStepBuilder::class)->build($intake->fresh() ?? $intake, $version);
    $preferred = collect($steps)->firstWhere('question_key', 'preferred_indoor_location');
    expect($preferred)->not->toBeNull()
        ->and($preferred['help_text'] ?? '')->toContain('bed')
        ->and($preferred['help_text'] ?? '')->not->toContain('bank');

    $closing = collect($steps)->firstWhere('kind', 'closing_wishes');
    expect($closing)->not->toBeNull();
    $closingHtml = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', collect($steps)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'closing_wishes'))
        ->set('activeStepKey', $closing['key'])
        ->html();

    expect(substr_count(mb_strtolower($closingHtml), 'zonder merkvoorkeur kiest de installateur wat het beste past'))->toBe(1)
        ->and(substr_count($closingHtml, 'Dit houdt de opname niet tegen'))->toBe(1);
});

test('huur toont verhuurderstoestemming en antwoord komt in klantantwoorden/rapport', function () {
    $intake = bl166Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'ownership', null, ['value' => 'rented']);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh() ?? $intake, $version);
    expect(collect($steps)->pluck('question_key')->all())->toContain('landlord_permission');

    $landlordIndex = collect($steps)->search(fn (array $s): bool => ($s['question_key'] ?? null) === 'landlord_permission');
    expect($landlordIndex)->not->toBeFalse();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $landlordIndex)
        ->set('activeStepKey', $steps[$landlordIndex]['key'])
        ->assertSee('Weet je verhuurder ervan?')
        ->assertSee('Nog niet gevraagd')
        ->assertSee('Ja, die vindt het goed')
        ->assertSee('Nee, die is tegen')
        ->assertSee('meestal toestemming nodig');

    $save->handle($intake, 'landlord_permission', null, ['value' => 'yes']);
    $blocks = CustomerAnswerBlocks::forIntake($intake->fresh(['answers', 'templateVersion.sections.questions.options']) ?? $intake);
    $flat = collect($blocks)->flatMap(fn (array $block) => $block['items']);
    expect($flat->first(fn (array $item): bool => str_contains(mb_strtolower($item['label']), 'verhuurder'))['value'] ?? null)
        ->toBe('Ja, die vindt het goed');

    // Koop → geen verhuurvraag.
    $owned = bl166Intake(['customer_email' => 'bl166-owned@example.com']);
    $save->handle($owned, 'ownership', null, ['value' => 'owned']);
    $ownedSteps = app(IntakeStepBuilder::class)->build(
        $owned->fresh() ?? $owned,
        $owned->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );
    expect(collect($ownedSteps)->pluck('question_key')->all())->not->toContain('landlord_permission');
});
