<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\AiRunStatus;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeSuggestIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
    ]);

    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'no']);
    app(SaveIntakeAnswer::class)->handle($intake, 'natural_fall_possible', null, ['bool' => false]);

    return $intake->fresh();
}

test('heuristic derives attention points as proposed', function () {
    config(['ai.provider' => 'heuristic']);
    $intake = makeSuggestIntake();

    $run = app(SuggestAttentionPoints::class)->handle($intake);

    expect($run->status)->toBe(AiRunStatus::Succeeded);

    $points = $intake->fresh()->attentionPoints;
    $codes = $points->pluck('code')->all();

    expect($codes)->toContain('no_free_group')
        ->and($codes)->toContain('condensate_pump_maybe')
        ->and($points->every(fn ($p) => $p->source === AttentionPointSource::Ai))
        ->and($points->every(fn ($p) => $p->status === AttentionPointStatus::Proposed))->toBeTrue();
});

test('re-running does not duplicate points', function () {
    config(['ai.provider' => 'heuristic']);
    $intake = makeSuggestIntake();

    app(SuggestAttentionPoints::class)->handle($intake);
    $first = $intake->fresh()->attentionPoints()->count();
    app(SuggestAttentionPoints::class)->handle($intake->fresh());
    $second = $intake->fresh()->attentionPoints()->count();

    expect($second)->toBe($first);
});

test('a dismissed point stays dismissed after re-running', function () {
    config(['ai.provider' => 'heuristic']);
    $intake = makeSuggestIntake();

    app(SuggestAttentionPoints::class)->handle($intake);
    $point = $intake->fresh()->attentionPoints()->where('code', 'no_free_group')->firstOrFail();
    $point->update(['status' => AttentionPointStatus::Dismissed]);

    app(SuggestAttentionPoints::class)->handle($intake->fresh());

    expect($intake->fresh()->attentionPoints()->where('code', 'no_free_group')->value('status'))
        ->toBe(AttentionPointStatus::Dismissed);
});

test('null provider soft-fails without creating points', function () {
    config(['ai.provider' => 'null']);
    $intake = makeSuggestIntake();

    $run = app(SuggestAttentionPoints::class)->handle($intake);

    expect($run->status)->toBe(AiRunStatus::Failed)
        ->and($intake->fresh()->attentionPoints()->count())->toBe(0);
});
