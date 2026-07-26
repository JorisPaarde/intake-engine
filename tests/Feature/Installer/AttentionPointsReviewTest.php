<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Jobs\GenerateIntakePdfJob;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    config(['ai.provider' => 'heuristic']);
});

function makeReviewableIntake(User $owner): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create(['created_by' => $owner->id, 'intake_template_version_id' => $version->id]);

    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'no']);
    app(SaveIntakeAnswer::class)->handle($intake, 'natural_fall_possible', null, ['bool' => false]);

    GeneratedReport::query()->create([
        'intake_id' => $intake->id,
        'html' => '<html><body>rapport</body></html>',
        'meta' => [],
        'generated_at' => now(),
    ]);

    return $intake->fresh();
}

test('installer reviews automatically generated AI attention points without a generation button', function () {
    Queue::fake([GenerateIntakePdfJob::class]);
    $owner = User::factory()->create();
    $intake = makeReviewableIntake($owner);

    app(SuggestAttentionPoints::class)->handle($intake);

    $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('AI-voorgestelde aandachtspunten')
        ->assertDontSee('AI-aandachtspunten voorstellen')
        ->assertDontSee('Opnieuw voorstellen');

    $proposed = $intake->fresh()->attentionPoints()->aiProposed()->get();
    expect($proposed->pluck('code')->all())->toContain('no_free_group', 'condensate_pump_maybe');

    $accept = $proposed->firstWhere('code', 'no_free_group');
    $dismiss = $proposed->firstWhere('code', 'condensate_pump_maybe');

    $this->actingAs($owner)->post(route('intakes.attention.accept', [$intake, $accept]))->assertRedirect();
    $this->actingAs($owner)->post(route('intakes.attention.dismiss', [$intake, $dismiss]))->assertRedirect();

    expect($accept->fresh()->status)->toBe(AttentionPointStatus::Accepted)
        ->and($dismiss->fresh()->status)->toBe(AttentionPointStatus::Dismissed);
    Queue::assertPushed(GenerateIntakePdfJob::class, fn (GenerateIntakePdfJob $job): bool => $job->intakeId === $intake->id);

    $html = $intake->fresh()->report->html;
    expect($html)->toContain('Geen vrije groep bekend')
        ->and($html)->not->toContain('condenspomp');
});

test('installer sees AI confidence and dossier evidence before deciding', function () {
    $owner = User::factory()->create();
    $intake = makeReviewableIntake($owner);

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::Ai,
        'code' => 'building_type_check',
        'label' => 'Controleer het gebouwtype',
        'status' => AttentionPointStatus::Proposed,
        'ai_confidence' => 'high',
        'evidence' => [[
            'source_type' => 'external_fact',
            'reference' => 'building_type_inference',
        ]],
    ]);

    $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Zekerheid: hoog')
        ->assertSee('extern feit')
        ->assertSee('building_type_inference');
});

test('installer page safely renders a legacy AI proposal without provenance', function () {
    $owner = User::factory()->create();
    $intake = makeReviewableIntake($owner);

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::Ai,
        'code' => 'legacy_proposal',
        'label' => 'Bestaand voorstel zonder provenance',
        'status' => AttentionPointStatus::Proposed,
        'ai_confidence' => null,
        'evidence' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Bestaand voorstel zonder provenance')
        ->assertDontSee('Accepteren');

    $this->actingAs($owner)
        ->post(route('intakes.attention.accept', [$intake, $intake->attentionPoints()->where('code', 'legacy_proposal')->firstOrFail()]))
        ->assertNotFound();
});

test('automatic re-analysis keeps a dismissed point out and an accepted point in', function () {
    $owner = User::factory()->create();
    $intake = makeReviewableIntake($owner);

    app(SuggestAttentionPoints::class)->handle($intake);
    $point = $intake->fresh()->attentionPoints()->where('code', 'no_free_group')->firstOrFail();
    $this->actingAs($owner)->post(route('intakes.attention.dismiss', [$intake, $point]));

    app(SuggestAttentionPoints::class)->handle($intake->fresh());

    expect($intake->fresh()->attentionPoints()->where('code', 'no_free_group')->value('status'))
        ->toBe(AttentionPointStatus::Dismissed);
});

test('the manual attention point generation endpoint does not exist', function () {
    $owner = User::factory()->create();
    $intake = makeReviewableIntake($owner);

    $this->post("/intakes/{$intake->id}/attention/suggest")->assertNotFound();
});

test('a point from another intake cannot be accepted', function () {
    $owner = User::factory()->create();
    $intake = makeReviewableIntake($owner);
    $other = makeReviewableIntake($owner);

    $foreignPoint = IntakeAttentionPoint::query()->create([
        'intake_id' => $other->id,
        'source' => AttentionPointSource::Ai,
        'code' => 'no_free_group',
        'label' => 'x',
        'status' => AttentionPointStatus::Proposed,
    ]);

    $this->actingAs($owner)
        ->post(route('intakes.attention.accept', [$intake, $foreignPoint]))
        ->assertNotFound();

    expect($foreignPoint->fresh()->status)->toBe(AttentionPointStatus::Proposed);
});
