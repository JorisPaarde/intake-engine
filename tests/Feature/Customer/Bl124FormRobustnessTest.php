<?php

declare(strict_types=1);

use App\Domains\AI\Services\PhotoUsabilityHeuristic;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\PhotoUsabilityVerdict;
use App\Enums\RuleEffect;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeBl124Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    expect($version->version)->toBe(24);

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'access_token' => str_repeat('a', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

test('v21 combines length and width on one dimensions wizard screen', function () {
    $intake = makeBl124Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $keys = array_column($steps, 'question_key');
    $dimensionSteps = collect($steps)->filter(
        static fn (array $step): bool => ($step['kind'] ?? '') === 'question_group'
            && ($step['group_key'] ?? null) === 'room_dimensions',
    )->values();

    expect($dimensionSteps)->toHaveCount(1)
        ->and($dimensionSteps[0]['group_question_keys'])->toBe(['room_length_m', 'room_width_m'])
        ->and($keys)->toContain('room_length_m')
        ->and($keys)->not->toContain('room_width_m'); // width folded into the group primary

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $viewSteps = $component->viewData('steps');
    $index = collect($viewSteps)->search(
        static fn (array $step): bool => ($step['kind'] ?? '') === 'question_group'
            && ($step['group_key'] ?? null) === 'room_dimensions',
    );
    expect($index)->not->toBeFalse();

    $component->set('stepIndex', (int) $index)
        ->set('activeStepKey', $viewSteps[(int) $index]['key'])
        ->assertSee('Lengte en breedte van de ruimte')
        ->assertSee('data-testid="dimensions-group"', false)
        ->assertSee('Lengte (m)')
        ->assertSee('Breedte (m)');
});

test('dimensions stay optional when floor area m² is already known', function () {
    $intake = makeBl124Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_area_m2',
        'room-1',
        ['number' => 20],
        PrefillSources::AI_TEXT,
    );

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $step = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->firstWhere('group_key', 'room_dimensions');

    expect($step)->not->toBeNull()
        ->and($step['is_required'])->toBeFalse()
        ->and($step['help_text'])->toContain('m²');
});

test('drain photo remains optional with skip; pipe_route is installer-only; extra overview is assessment-gated', function () {
    $intake = makeBl124Intake();
    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();

    expect($version->version)->toBe(24);

    $pipe = $version->sections->flatMap->questions->firstWhere('key', 'pipe_route_photos');
    $drain = $version->sections->flatMap->questions->firstWhere('key', 'drain_photo');
    $indoor = $version->sections->flatMap->questions->firstWhere('key', 'indoor_unit_position_photo');

    expect($pipe->is_required)->toBeFalse()
        ->and($pipe->meta['audience'] ?? null)->toBe('installer')
        ->and($drain->is_required)->toBeFalse()
        ->and($drain->meta['allow_skip'] ?? null)->toBeTrue()
        ->and($drain->rules)->toBeEmpty()
        ->and($indoor->is_required)->toBeTrue()
        ->and($indoor->meta['allow_skip'] ?? null)->toBeNull()
        ->and($indoor->label)->toContain('Extra foto')
        ->and($indoor->rules->where('effect', RuleEffect::Show)->count())->toBeGreaterThan(0);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $viewSteps = $component->viewData('steps');
    $keys = array_column($viewSteps, 'question_key');

    expect($keys)->not->toContain('pipe_route_photos')
        ->and($keys)->not->toContain('indoor_unit_position_photo')
        ->and($keys)->toContain('drain_photo');

    $drainIndex = collect($viewSteps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'drain_photo',
    );
    expect($drainIndex)->not->toBeFalse();

    $beforeKey = $viewSteps[(int) $drainIndex]['key'];
    $component->set('stepIndex', (int) $drainIndex)
        ->set('activeStepKey', $beforeKey)
        ->assertSee('Weet ik niet / sla over')
        ->call('skipOptionalPhoto')
        ->assertSet('showMissing', false);

    expect($component->get('activeStepKey'))->not->toBe($beforeKey);
});

test('usability resolution uses original dimensions not a small resized frame', function () {
    // Simulate assessing a dossier-sized image while original capture was 3024×4032.
    $img = imagecreatetruecolor(400, 300);
    $colour = imagecolorallocate($img, 200, 200, 200);
    imagefill($img, 0, 0, $colour);
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    $heuristic = app(PhotoUsabilityHeuristic::class);

    expect($heuristic->assess($bytes))->toBe(PhotoUsabilityVerdict::TooSmall)
        ->and($heuristic->assess($bytes, 3024, 4032))->toBe(PhotoUsabilityVerdict::Ok);
});
