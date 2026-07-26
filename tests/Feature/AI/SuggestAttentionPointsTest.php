<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Services\IntakeAttentionContextBuilder;
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
use Illuminate\Database\QueryException;

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
        ->and($points->every(fn ($p) => $p->status === AttentionPointStatus::Proposed))->toBeTrue()
        ->and($points->every(fn ($p) => in_array($p->ai_confidence, ['medium', 'high'], true)))->toBeTrue()
        ->and($points->every(fn ($p) => is_array($p->evidence) && $p->evidence !== []))->toBeTrue();
});

test('heuristic skips a free-group proposal when the source answer is absent', function () {
    config(['ai.provider' => 'heuristic']);
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
    ]);

    $run = app(SuggestAttentionPoints::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($intake->attentionPoints()->whereIn('code', ['no_free_group', 'free_group_unknown'])->exists())->toBeFalse();
});

test('AI proposals retain confidence and evidence references', function () {
    config(['ai.provider' => 'fake']);
    FakeAiClient::reset();
    FakeAiClient::alwaysReturn(['points' => [[
        'code' => 'verify_building_type',
        'label' => 'Controleer het afgeleide woningtype.',
        'confidence' => 'medium',
        'evidence' => [[
            'source_type' => 'answer',
            'reference' => 'free_group_known',
        ]],
    ]]]);
    $intake = makeSuggestIntake();

    app(SuggestAttentionPoints::class)->handle($intake);

    $point = $intake->attentionPoints()->where('code', 'verify_building_type')->firstOrFail();

    expect($point->ai_confidence)->toBe('medium')
        ->and($point->evidence)->toBe([[
            'source_type' => 'answer',
            'reference' => 'free_group_known',
        ]]);
});

test('rejects evidence references that do not exist in the declared context source', function (string $sourceType, string $reference) {
    config(['ai.provider' => 'fake']);
    FakeAiClient::reset();
    FakeAiClient::alwaysReturn(['points' => [[
        'code' => 'unsupported_claim',
        'label' => 'Onvoldoende onderbouwd voorstel.',
        'confidence' => 'high',
        'evidence' => [[
            'source_type' => $sourceType,
            'reference' => $reference,
        ]],
    ]]]);
    $intake = makeSuggestIntake();

    $run = app(SuggestAttentionPoints::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Failed)
        ->and($intake->attentionPoints()->where('code', 'unsupported_claim')->exists())->toBeFalse();
})->with([
    'unknown answer reference' => ['answer', 'does_not_exist'],
    'reference exists under a different source type' => ['external_fact', 'free_group_known'],
]);

test('does not persist an AI result when dossier context changes during the provider call', function () {
    config(['ai.provider' => 'fake']);
    FakeAiClient::reset();
    $intake = makeSuggestIntake();

    FakeAiClient::respondUsing(function () use ($intake): array {
        app(SaveIntakeAnswer::class)->handle($intake->fresh(), 'free_group_known', null, ['value' => 'yes']);

        return ['points' => [[
            'code' => 'stale_no_free_group',
            'label' => 'Oud voorstel op basis van geen vrije groep.',
            'confidence' => 'high',
            'evidence' => [[
                'source_type' => 'answer',
                'reference' => 'free_group_known',
            ]],
        ]]];
    });

    $run = app(SuggestAttentionPoints::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Failed)
        ->and($intake->attentionPoints()->where('code', 'stale_no_free_group')->exists())->toBeFalse();
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

test('database rejects duplicate coded attention points for one source', function () {
    $intake = makeSuggestIntake();
    $attributes = [
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::Ai,
        'code' => 'no_free_group',
        'label' => 'Geen vrije groep bekend.',
        'status' => AttentionPointStatus::Proposed,
    ];

    IntakeAttentionPoint::query()->create($attributes);

    expect(fn () => IntakeAttentionPoint::query()->create($attributes))
        ->toThrow(QueryException::class);
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

test('context construction failures soft-fail without escaping the action', function () {
    config(['ai.provider' => 'fake']);
    $invalidIntake = new Intake;
    $invalidIntake->id = PHP_INT_MAX;

    $run = app(SuggestAttentionPoints::class)->handle($invalidIntake);

    expect($run)->toBeNull();
});

test('null provider soft-fails without creating points', function () {
    config(['ai.provider' => 'null']);
    $intake = makeSuggestIntake();

    $run = app(SuggestAttentionPoints::class)->handle($intake);

    expect($run->status)->toBe(AiRunStatus::Failed)
        ->and($intake->fresh()->attentionPoints()->count())->toBe(0);
});

test('context emits unique evidence references for repeated answers and uploads', function () {
    $intake = makeSuggestIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-2', ['value' => 'bedroom']);

    foreach (['room-1', 'room-2'] as $index => $instance) {
        IntakeUpload::query()->create([
            'intake_id' => $intake->id,
            'question_key' => 'room_photo',
            'section_instance_key' => $instance,
            'disk' => 'local',
            'path' => 'private/'.$instance.'.jpg',
            'original_filename' => $instance.'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'sort_order' => $index,
        ]);
    }

    $payload = app(IntakeAttentionContextBuilder::class)->build($intake->fresh());
    $answerReferences = collect($payload['answer_context'])->pluck('reference')->all();
    $uploadReferences = collect($payload['uploads'])->pluck('reference')->all();

    expect($answerReferences)->toContain('room_type@section:room-1', 'room_type@section:room-2')
        ->and($uploadReferences)->toHaveCount(2)
        ->and(collect($uploadReferences)->every(
            static fn (string $reference): bool => preg_match('/^room_photo@section:room-[12]@upload:upload_[a-f0-9]{16}$/', $reference) === 1,
        ))->toBeTrue()
        ->and(array_unique($answerReferences))->toHaveCount(count($answerReferences))
        ->and(array_unique($uploadReferences))->toHaveCount(count($uploadReferences));
});

test('AI context excludes sensitive fact types and dotted location references', function () {
    $intake = makeSuggestIntake();

    foreach ([
        'location' => ['latitude' => 52.1],
        'parcel_ids' => ['values' => ['AMS00-A-1234']],
        'aerial_image' => ['bbox_epsg_3857' => [1, 2, 3, 4]],
    ] as $factKey => $value) {
        IntakeExternalFact::query()->create([
            'intake_id' => $intake->id,
            'fact_key' => $factKey,
            'label' => $factKey,
            'value' => $value,
            'source' => 'PDOK',
            'source_reference' => 'sensitive-reference',
            'confidence' => 'high',
            'captured_at' => now(),
        ]);
    }

    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'building_type_inference',
        'label' => 'Woningtype',
        'value' => [
            'option' => 'corner',
            'pand.href' => 'https://example.test/pand/secret',
            'source.url' => 'https://example.test/location',
            'bag.pand.identificatie' => '0363100012999999',
            'parcel_id' => 'AMS00-A-1234',
            'bbox_epsg_3857' => [1, 2, 3, 4],
        ],
        'source' => 'PDOK',
        'source_reference' => '0363100012999999',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    $payload = app(IntakeAttentionContextBuilder::class)->build($intake->fresh());

    expect($payload['external_facts'])->not->toHaveKeys(['location', 'parcel_ids', 'aerial_image'])
        ->and(collect($payload['external_fact_context'])->pluck('fact_key')->all())
        ->not->toContain('location', 'parcel_ids', 'aerial_image')
        ->and($payload['external_facts']['building_type_inference']['value'])
        ->toBe(['option' => 'corner']);
});

test('attention point analysis receives the complete technical dossier context', function () {
    config(['ai.provider' => 'fake']);
    FakeAiClient::reset();
    FakeAiClient::alwaysReturn(['points' => []]);

    $intake = makeSuggestIntake();
    $intake->answers()->where('question_key', 'free_group_known')->firstOrFail()->update([
        'value' => ['value' => 'no', 'upload_ids' => [987654]],
    ]);
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
        'value' => [
            'option' => 'terraced',
            'reason' => 'Panden sluiten aan beide zijden aan.',
            'upload_ids' => [123, 456],
        ],
        'source' => 'PDOK / BAG pandgeometrie',
        'source_reference' => 'pand-1',
        'source_url' => 'https://example.test/pand-1',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'location',
        'label' => 'Locatie',
        'value' => [
            'latitude' => 52.123456,
            'longitude' => 4.123456,
            'municipality' => 'Testdam',
            'province' => 'Testland',
            'bbox' => [4.1, 52.1, 4.2, 52.2],
            'geometry' => ['coordinates' => [155000.5, 463000.5]],
            'identificatie' => '0363100012999999',
            'source_url' => 'https://geo.example.test/secret-location',
            'technical_note' => str_repeat('x', 150_000),
        ],
        'source' => 'PDOK',
        'source_reference' => '0363100012185508',
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
        ->and($input['external_fact_context'][0]['value'])->not->toHaveKey('upload_ids')
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
        ->and(strlen((string) json_encode($input)))->toBeLessThanOrEqual(100_000)
        ->and(json_encode($input))->not->toContain(
            '"upload_id"',
            '"upload_ids"',
            '"follow_up_item_id"',
            '"intake_id"',
            'Testlaan',
            'Testdam',
            'Testland',
            '52.123456',
            '4.123456',
            '0363100012185508',
            '0363100012999999',
            '155000.5',
            '463000.5',
            'geo.example.test',
            'example.com',
            'private/fusebox.jpg',
        );
});
