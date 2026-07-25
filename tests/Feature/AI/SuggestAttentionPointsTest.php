<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeReview;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Enums\AiRunStatus;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\PipeRouteStatus;
use App\Enums\ReviewDecision;
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

test('attention point analysis receives the complete technical dossier context', function () {
    config(['ai.provider' => 'fake']);
    FakeAiClient::reset();
    FakeAiClient::alwaysReturn(['points' => []]);

    $intake = makeSuggestIntake();
    $intake->update([
        'completeness_snapshot' => [
            'is_complete' => true,
            'attention_points' => [['code' => 'photo_check', 'label' => 'Controleer de foto.']],
        ],
    ]);

    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'building_type_inference',
        'label' => 'Afgeleid woningtype',
        'value' => ['option' => 'terraced', 'reason' => 'Panden sluiten aan beide zijden aan.'],
        'source' => 'PDOK / BAG pandgeometrie',
        'source_reference' => 'pand-1',
        'source_url' => 'https://example.test/pand-1',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'fusebox_photo',
        'section_instance_key' => null,
        'disk' => 'local',
        'path' => 'private/fusebox.jpg',
        'original_filename' => 'meterkast.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 12345,
        'sort_order' => 0,
        'usability_verdict' => 'too_dark',
    ]);

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::System,
        'code' => 'photo_check',
        'label' => 'Controleer de foto.',
        'is_resolved' => false,
    ]);

    IntakeReview::query()->create([
        'intake_id' => $intake->id,
        'reviewer_id' => $intake->created_by,
        'decision' => ReviewDecision::NeedMoreInfo,
        'site_visit_needed' => true,
        'enough_information' => false,
        'summary' => 'Controleer de route na de aanvulling.',
        'reviewed_at' => now(),
    ]);

    PipeRouteSession::query()->create([
        'intake_id' => $intake->id,
        'status' => PipeRouteStatus::Proposed,
        'confidence' => 0.72,
        'proposed_route' => ['Via de linker zijgevel.'],
        'alternative_route' => ['Via de achtergevel.'],
        'uncertainties' => ['Doorvoer niet volledig zichtbaar.'],
        'missing_checks' => ['Controleer leidinglengte.'],
        'next_photo_instruction' => null,
    ]);

    app(SuggestAttentionPoints::class)->handle($intake->fresh());

    $input = FakeAiClient::lastRequest()?->input;

    expect($input)->not->toBeNull()
        ->and($input['answer_context'][0])->toHaveKeys([
            'question_key', 'question_label', 'section_label', 'answer', 'prefill_source',
        ])
        ->and($input['external_fact_context'][0])->toMatchArray([
            'fact_key' => 'building_type_inference',
            'label' => 'Afgeleid woningtype',
            'source' => 'PDOK / BAG pandgeometrie',
            'confidence' => 'high',
        ])
        ->and($input['uploads'][0])->toMatchArray([
            'question_key' => 'fusebox_photo',
            'question_label' => 'Foto van de meterkast',
            'mime_type' => 'image/jpeg',
            'usability_verdict' => 'too_dark',
        ])
        ->and($input['system_attention_points'][0])->toMatchArray([
            'code' => 'photo_check',
            'label' => 'Controleer de foto.',
        ])
        ->and($input['completeness']['is_complete'])->toBeTrue()
        ->and($input['installer_review'])->toMatchArray([
            'decision' => 'need_more_info',
            'site_visit_needed' => true,
            'enough_information' => false,
        ])
        ->and($input['pipe_routes'][0])->toMatchArray([
            'status' => 'proposed',
            'confidence' => 0.72,
            'missing_checks' => ['Controleer leidinglengte.'],
        ])
        ->and(json_encode($input))->not->toContain('Testlaan', 'example.com', 'private/fusebox.jpg');
});
