<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

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

test('installer suggests, accepts and dismisses AI attention points', function () {
    $owner = User::factory()->create();
    $intake = makeReviewableIntake($owner);

    $this->actingAs($owner)
        ->post(route('intakes.attention.suggest', $intake))
        ->assertRedirect(route('intakes.show', $intake));

    $proposed = $intake->fresh()->attentionPoints()->aiProposed()->get();
    expect($proposed->pluck('code')->all())->toContain('no_free_group', 'condensate_pump_maybe');

    $accept = $proposed->firstWhere('code', 'no_free_group');
    $dismiss = $proposed->firstWhere('code', 'condensate_pump_maybe');

    $this->actingAs($owner)->post(route('intakes.attention.accept', [$intake, $accept]))->assertRedirect();
    $this->actingAs($owner)->post(route('intakes.attention.dismiss', [$intake, $dismiss]))->assertRedirect();

    expect($accept->fresh()->status)->toBe(AttentionPointStatus::Accepted)
        ->and($dismiss->fresh()->status)->toBe(AttentionPointStatus::Dismissed);

    // Accepted point is rebuilt into the report; dismissed is not.
    $html = $intake->fresh()->report->html;
    expect($html)->toContain('Geen vrije groep bekend')
        ->and($html)->not->toContain('condenspomp');
});

test('re-suggesting keeps a dismissed point out and an accepted point in', function () {
    $owner = User::factory()->create();
    $intake = makeReviewableIntake($owner);

    $this->actingAs($owner)->post(route('intakes.attention.suggest', $intake));
    $point = $intake->fresh()->attentionPoints()->where('code', 'no_free_group')->firstOrFail();
    $this->actingAs($owner)->post(route('intakes.attention.dismiss', [$intake, $point]));

    $this->actingAs($owner)->post(route('intakes.attention.suggest', $intake));

    expect($intake->fresh()->attentionPoints()->where('code', 'no_free_group')->value('status'))
        ->toBe(AttentionPointStatus::Dismissed);
});

test('a guest cannot act on attention points', function () {
    $owner = User::factory()->create();
    $intake = makeReviewableIntake($owner);

    $this->post(route('intakes.attention.suggest', $intake))->assertRedirect(route('login'));
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
