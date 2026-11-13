<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\MustAcceptQuestions;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function bl169Intake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $company = Company::factory()->create(['name' => 'CoolPro Airco']);

    return Intake::factory()->create(array_merge([
        'company_id' => $company->id,
        'created_by' => User::factory()->for($company)->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'source' => IntakeSource::Embed,
        'customer_name' => 'UX Tester',
        'customer_email' => 'bl169@example.test',
        'address_line' => 'Lepelstraat 1',
        'address_postal_code' => '2011RD',
        'address_city' => 'Haarlem',
        'address_house_number' => '1',
    ], $overrides));
}

function bl169SeedHousing(Intake $intake): void
{
    $intake->externalFacts()->create([
        'fact_key' => 'building_year',
        'label' => 'Bouwjaar',
        'value' => ['number' => 1920],
        'source' => 'PDOK / BAG',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);
    $intake->externalFacts()->create([
        'fact_key' => 'energy_label_status',
        'label' => 'Energielabel status',
        'value' => ['status' => 'not_found'],
        'source' => 'EP-Online',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);
    $intake->externalFacts()->create([
        'fact_key' => 'usage_purposes',
        'label' => 'Gebruiksdoelen',
        'value' => ['values' => ['winkelfunctie']],
        'source' => 'PDOK / BAG',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    app(SaveIntakeAnswer::class)->handle($intake, 'build_year', null, ['number' => 1920], 'pdok');
    app(SaveIntakeAnswer::class)->handle($intake, 'building_type', null, ['value' => 'commercial'], 'pdok');
}

test('BL-169: postcode in adres heeft spatie', function () {
    $intake = bl169Intake();

    expect($intake->formattedPostalCode())->toBe('2011 RD')
        ->and($intake->fullAddress())->toBe('Lepelstraat 1, 2011 RD Haarlem');
});

test('BL-169: stap 1 toont één pandregel zonder woonfunctie-/energielabel-meldingen', function () {
    $intake = bl169Intake();
    bl169SeedHousing($intake);

    expect($intake->customerPropertySummaryLine())->toContain('Lepelstraat 1, 2011 RD Haarlem')
        ->and($intake->customerPropertySummaryLine())->toContain('bouwjaar 1920')
        ->and($intake->nonResidentialCustomerNote())->not->toBeNull()
        ->and($intake->energyLabelCustomerNote())->not->toBeNull();

    $html = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])->html();

    expect($html)->toContain('data-testid="wizard-intro"')
        ->and($html)->toContain('Lepelstraat 1, 2011 RD Haarlem')
        ->and($html)->toContain('bouwjaar 1920')
        ->and($html)->not->toContain('data-testid="non-residential-note"')
        ->and($html)->not->toContain('data-testid="energy-label-note"')
        ->and($html)->not->toContain('Geen energielabel gevonden voor dit adres')
        ->and($html)->not->toContain('geen woonfunctie')
        ->and($html)->not->toContain('data-testid="known-housing-facts"')
        ->and($html)->toContain('Je antwoorden worden automatisch bewaard.');
});

test('BL-169: pandblok niet op known-summary of latere stap', function () {
    $intake = bl169Intake();
    bl169SeedHousing($intake);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'living_room'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'cooling_heating', null, ['value' => 'cooling'], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->fresh()->access_token]);
    $steps = $component->viewData('steps');

    $summaryIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['kind'] ?? '') === 'known_summary',
    );
    expect($summaryIndex)->toBeInt();

    $component->set('stepIndex', $summaryIndex)->set('activeStepKey', $steps[$summaryIndex]['key']);
    expect($component->viewData('showWizardIntro'))->toBeFalse()
        ->and($component->html())->not->toContain('data-testid="wizard-intro"');

    $later = collect($steps)->search(
        static fn (array $step, int $index): bool => $index > 0 && ($step['kind'] ?? '') !== 'known_summary',
    );
    expect($later)->toBeInt();
    $component->set('stepIndex', $later)->set('activeStepKey', $steps[$later]['key']);
    expect($component->viewData('showWizardIntro'))->toBeFalse();
});

test('BL-169: leeg verplicht request_reason blokkeert Volgende met veldmelding', function () {
    $intake = bl169Intake(['source' => IntakeSource::Embed]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    $reasonIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? '') === 'request_reason',
    );
    expect($reasonIndex)->toBeInt()->and($reasonIndex)->toBe(0);

    $component
        ->set('stepIndex', $reasonIndex)
        ->set('activeStepKey', $steps[$reasonIndex]['key'])
        ->set('form.request_reason.text', '')
        ->call('next', $steps[$reasonIndex]['key'])
        ->assertSet('showMissing', true)
        ->assertSet('waitingForPrefill', false)
        ->assertSeeHtml('data-testid="field-required-error"')
        ->assertSee('Beantwoord eerst deze verplichte vraag.')
        ->assertSeeHtml('placeholder="Bijv. airco in woonkamer en slaapkamer, alleen koelen"');

    expect($component->get('stepIndex'))->toBe($reasonIndex);
});

test('BL-169: Afronden-teksten en één validatiemelding onder checkbox', function () {
    $intake = bl169Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    // Embed-achtig: privacy al gegeven → truth_confirmation is de laatste stap.
    app(SaveIntakeAnswer::class)->handle($intake, 'privacy_consent', null, ['bool' => true]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    $truthIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? '') === 'truth_confirmation',
    );
    expect($truthIndex)->toBeInt();

    $truth = $steps[$truthIndex];
    expect($truth['title'])->toBe('Klaar om te versturen?')
        ->and($truth['description'])->toBeNull()
        ->and(MustAcceptQuestions::refusalMessage('truth_confirmation'))->toBe('Vink dit aan om te versturen.');

    $html = $component
        ->set('stepIndex', $truthIndex)
        ->set('activeStepKey', $truth['key'])
        ->set('form.truth_confirmation.bool', false)
        ->set('showMissing', true)
        ->html();

    expect($html)->toContain('Klaar om te versturen?')
        ->and($html)->toContain('Ik heb alles naar waarheid ingevuld.')
        ->and($html)->toContain('Versturen naar CoolPro Airco')
        ->and($html)->not->toContain('Laatste opmerkingen en toestemming')
        ->and($html)->toContain('Vink dit aan om te versturen.')
        ->and($html)->not->toContain('data-testid="step-missing-alert"')
        ->and(substr_count($html, 'Vink dit aan om te versturen.'))->toBe(1)
        ->and(substr_count($html, 'Beantwoord eerst deze verplichte vraag.'))->toBe(0);
});

test('BL-169: merkstap is radio + select en blijft in de flow na prefill', function () {
    $intake = bl169Intake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    $save->handle(
        $intake,
        'brand_preference',
        null,
        ['values' => ['no_preference']],
        PrefillSources::AI_TEXT,
    );
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh() ?? $intake,
        $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );
    $closing = collect($steps)->firstWhere('kind', 'closing_wishes');
    expect($closing)->not->toBeNull()
        ->and($closing['bundle_question_keys'] ?? [])->toContain('brand_preference');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', collect($steps)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'closing_wishes'))
        ->set('activeStepKey', $closing['key']);

    $html = $component->html();
    expect($html)->toContain('data-testid="brand-preference-mode"')
        ->and($html)->toContain('Geen voorkeur (installateur kiest)')
        ->and($html)->toContain('Ik heb een voorkeur')
        ->and($html)->toContain('data-testid="brand-mode-none"');

    $component->call('setBrandPreferenceMode', 'prefer')
        ->assertSet('brandPreferenceChoice', 'prefer');
    expect($component->html())->toContain('data-testid="brand-preference-select"');

    $component->call('setBrandPreferenceBrand', 'daikin');
    $values = $intake->fresh()->answers()->where('question_key', 'brand_preference')->firstOrFail()->value['values'] ?? [];
    expect($values)->toBe(['daikin']);

    $component->call('setBrandPreferenceMode', 'none');
    $values = $intake->fresh()->answers()->where('question_key', 'brand_preference')->firstOrFail()->value['values'] ?? [];
    expect($values)->toBe(['no_preference']);
});

test('BL-169: known-summary groepeert per ruimte en Wijzigen keert terug', function () {
    $intake = bl169Intake(['source' => IntakeSource::Installer]);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'cooling_heating', null, ['value' => 'cooling'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 2], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'living_room'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_name', 'room-1', ['text' => 'Woonkamer'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-2', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_name', 'room-2', ['text' => 'Slaapkamer'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_length_m', 'room-1', ['number' => 4], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_width_m', 'room-1', ['number' => 3], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh() ?? $intake,
        $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );
    $summary = collect($steps)->firstWhere('kind', 'known_summary');
    expect($summary)->not->toBeNull();

    $items = collect($summary['known_items'] ?? []);
    $roomLabels = $items->whereNotNull('section_instance_key')->pluck('label')->all();
    expect($roomLabels)->toContain('Woonkamer')
        ->and($roomLabels)->toContain('Slaapkamer')
        ->and($items->pluck('label')->implode(' | '))->not->toContain('Hoe heet deze ruimte')
        ->and($summary['help_text'])->toBe('De rest vragen we hierna.');

    $summaryIndex = collect($steps)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $summaryIndex)
        ->set('activeStepKey', $steps[$summaryIndex]['key']);

    expect($component->html())->toContain('De rest vragen we hierna.')
        ->and($component->html())->toContain('data-testid="known-summary-edit"')
        ->and($component->html())->toContain('Je voortgang blijft bewaard via deze link tot je afrondt.');

    $component->call('editKnownAnswer', 'room_name', 'room-1');
    expect($component->get('forceShowKnown'))->toContain('room-1__room_name');

    $component
        ->set('form.room-1__room_name.text', 'Woonkamer voor')
        ->call('next');

    $stepsAfter = $component->viewData('steps');
    $summaryAfter = collect($stepsAfter)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');
    expect($component->get('stepIndex'))->toBe($summaryAfter);
});
