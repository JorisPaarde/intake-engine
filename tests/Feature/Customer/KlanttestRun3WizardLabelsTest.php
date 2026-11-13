<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function run3WizardIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Run3 Test',
        'customer_email' => 'run3@example.test',
        'address_postal_code' => '2037GR',
        'address_house_number' => '273',
    ]);
}

test('known-summary toont berekend oppervlak als getal met L×B-toelichting', function () {
    $intake = run3WizardIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'living_room'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_length_m', 'room-1', ['number' => 3], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_width_m', 'room-1', ['number' => 5], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh(['answers', 'aircoRooms', 'templateVersion.sections.questions.options']),
        $intake->templateVersion,
    );
    $summary = collect($steps)->firstWhere('question_key', '_known_summary');
    // BL-169: één rij per ruimte; m² staat als display_value op die rij.
    $roomRow = collect($summary['known_items'] ?? [])
        ->first(static fn (array $item): bool => ($item['section_instance_key'] ?? null) === 'room-1');

    expect($roomRow)->not->toBeNull()
        ->and($roomRow['display_value'])->toBe('15 m² (berekend uit 3 × 5 m)')
        ->and($roomRow['display_value'])->not->toBe('berekend uit L×B')
        ->and($roomRow['label'])->not->toContain('Hoe heet deze ruimte');
});

test('ruimte-eyebrow nummert overall: Kamer N van M (type) bij gemengde types', function () {
    $intake = run3WizardIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 3], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-2', ['value' => 'living_room'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-3', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh(['answers', 'aircoRooms', 'templateVersion.sections.questions.options']),
        $intake->templateVersion,
    );

    $titles = collect($steps)
        ->where('question_key', 'room_photos')
        ->sortBy('section_instance_key')
        ->pluck('section_title')
        ->values()
        ->all();

    expect($titles)->toBe([
        'Kamer 1 van 3 (slaapkamer)',
        'Kamer 2 van 3 (woonkamer)',
        'Kamer 3 van 3 (slaapkamer)',
    ]);
});

test('wizard toont nooit Vraag N; voortgang % daalt niet na Volgende of herladen', function () {
    $intake = run3WizardIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 2], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-2', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $html = $component->html();

    expect($html)->not->toMatch('/Vraag \d+/')
        ->and($html)->not->toMatch('/Vraag \d+ van \d+/')
        ->and($html)->toContain('data-testid="progress-percent"')
        ->and($html)->toContain('Voortgang via balk');

    $percentBefore = (int) $component->viewData('progressPercent');
    $steps = $component->viewData('steps');
    expect($steps)->not->toBeEmpty();

    $component
        ->set('stepIndex', 0)
        ->set('activeStepKey', $steps[0]['key'])
        ->call('next', $steps[0]['key']);

    expect((int) $component->viewData('progressPercent'))->toBeGreaterThanOrEqual($percentBefore)
        ->and($component->html())->not->toMatch('/Vraag \d+/');

    $reloaded = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $reloadedPercent = (int) $reloaded->viewData('progressPercent');
    $reloadedSteps = $reloaded->viewData('steps');
    $key = $reloaded->get('activeStepKey') ?: ($reloadedSteps[0]['key'] ?? '');
    expect($key)->not->toBe('');

    $reloaded->call('next', $key);

    expect((int) $reloaded->viewData('progressPercent'))->toBeGreaterThanOrEqual($reloadedPercent)
        ->and($reloaded->html())->not->toMatch('/Vraag \d+/');
});

test('Vorige-knoppen hebben geen clipboard-write en wissen selectie-hook is aanwezig', function () {
    $intake = run3WizardIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    expect(count($steps))->toBeGreaterThan(1);

    $html = $component
        ->set('stepIndex', 1)
        ->set('activeStepKey', $steps[1]['key'])
        ->html();

    expect($html)->toContain('data-testid="footer-previous"')
        ->and($html)->not->toContain('navigator.clipboard')
        ->and($html)->not->toContain('execCommand')
        ->and($html)->not->toContain('writeText');

    $customerJs = file_get_contents(resource_path('js/customer.js'));
    expect($customerJs)->toContain('__intakeClearWizardSelection')
        ->and($customerJs)->toContain('footer-previous')
        ->and($customerJs)->not->toContain('navigator.clipboard.writeText')
        ->and($customerJs)->not->toMatch('/execCommand\(\s*[\'"]copy[\'"]\s*\)/');
});
