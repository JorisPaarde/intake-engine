<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function bl167WizardIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'is_demo' => true,
        'customer_name' => 'Intro Zichtbaarheid',
        'customer_email' => 'bl167@example.test',
        'address_line' => 'Bernadottelaan 273',
        'address_postal_code' => '2037GR',
        'address_city' => 'Haarlem',
        'address_house_number' => '273',
    ], $overrides));
}

function bl167SeedKnownHousing(Intake $intake): void
{
    $intake->externalFacts()->create([
        'fact_key' => 'building_year',
        'label' => 'Bouwjaar',
        'value' => ['number' => 1971],
        'source' => 'PDOK / BAG',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'build_year',
        null,
        ['number' => 1971],
        'pdok',
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'building_type',
        null,
        ['value' => 'terraced'],
        'pdok',
    );
}

/**
 * @return array{0: Testable, 1: list<array<string, mixed>>}
 */
function bl167OpenWizard(Intake $intake): array
{
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    /** @var list<array<string, mixed>> $steps */
    $steps = $component->viewData('steps');

    return [$component, $steps];
}

test('BL-167: intro + demobanner alleen op eerste stap en known-summary', function () {
    $intake = bl167WizardIntake();
    bl167SeedKnownHousing($intake);

    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_length_m', 'room-1', ['number' => 3], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_width_m', 'room-1', ['number' => 5], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    [$component, $steps] = bl167OpenWizard($intake->fresh() ?? $intake);
    expect($steps)->not->toBeEmpty()
        ->and(count($steps))->toBeGreaterThan(2);

    $firstKey = $steps[0]['key'];
    $component->set('stepIndex', 0)->set('activeStepKey', $firstKey);
    $htmlFirst = $component->html();

    expect($component->viewData('showWizardIntro'))->toBeTrue()
        ->and($htmlFirst)->toContain('data-testid="wizard-intro"')
        ->and($htmlFirst)->toContain('data-testid="demo-scope-banner"')
        ->and($htmlFirst)->toContain('Demo — wat de klant ziet')
        ->and($htmlFirst)->toContain('data-testid="known-housing-facts"')
        ->and($htmlFirst)->toContain('data-testid="progress-percent"')
        ->and($htmlFirst)->toContain('Intro Zichtbaarheid');

    $laterIndex = min(2, count($steps) - 1);
    expect($laterIndex)->toBeGreaterThan(0);
    $laterKey = $steps[$laterIndex]['key'];
    expect(($steps[$laterIndex]['kind'] ?? 'question'))->not->toBe('known_summary');

    $component->set('stepIndex', $laterIndex)->set('activeStepKey', $laterKey);
    $htmlLater = $component->html();

    expect($component->viewData('showWizardIntro'))->toBeFalse()
        ->and($htmlLater)->not->toContain('data-testid="wizard-intro"')
        ->and($htmlLater)->not->toContain('data-testid="demo-scope-banner"')
        ->and($htmlLater)->not->toContain('Demo — wat de klant ziet')
        ->and($htmlLater)->not->toContain('data-testid="known-housing-facts"')
        ->and($htmlLater)->not->toContain('Met jouw hulp kunnen we sneller je airco plaatsen')
        ->and($htmlLater)->toContain('data-testid="progress-percent"')
        ->and($htmlLater)->toContain('Intro Zichtbaarheid');

    $summaryIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['kind'] ?? '') === 'known_summary'
            || ($step['question_key'] ?? '') === '_known_summary',
    );
    expect($summaryIndex)->toBeInt();

    $summaryKey = $steps[$summaryIndex]['key'];
    $component->set('stepIndex', $summaryIndex)->set('activeStepKey', $summaryKey);
    $htmlSummary = $component->html();

    expect($component->viewData('showWizardIntro'))->toBeTrue()
        ->and($htmlSummary)->toContain('data-testid="wizard-intro"')
        ->and($htmlSummary)->toContain('data-testid="demo-scope-banner"')
        ->and($htmlSummary)->toContain('Dit hebben we al uit je aanvraag')
        ->and($htmlSummary)->toContain('data-testid="progress-percent"');
});

test('BL-167: zonder demo geen demobanner; intro verdwijnt na eerste stap', function () {
    $intake = bl167WizardIntake(['is_demo' => false]);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'living_room'], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    [$component, $steps] = bl167OpenWizard($intake->fresh() ?? $intake);
    expect(count($steps))->toBeGreaterThan(1);

    $component->set('stepIndex', 0)->set('activeStepKey', $steps[0]['key']);
    $htmlFirst = $component->html();

    expect($htmlFirst)->toContain('data-testid="wizard-intro"')
        ->and($htmlFirst)->not->toContain('data-testid="demo-scope-banner"')
        ->and($htmlFirst)->toContain('Beantwoord de vragen hieronder');

    $laterIndex = collect($steps)->search(
        static fn (array $step, int $index): bool => $index > 0
            && ($step['kind'] ?? 'question') !== 'known_summary',
    );
    expect($laterIndex)->toBeInt();

    $component->set('stepIndex', $laterIndex)->set('activeStepKey', $steps[$laterIndex]['key']);
    expect($component->viewData('showWizardIntro'))->toBeFalse()
        ->and($component->get('stepIndex'))->toBe($laterIndex);

    $htmlSecond = $component->html();

    expect($htmlSecond)->not->toContain('data-testid="wizard-intro"')
        ->and($htmlSecond)->not->toContain('Beantwoord de vragen hieronder')
        ->and($htmlSecond)->toContain('data-testid="progress-percent"');
});
