<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Eval\EvalPhotoVisionInvoker;
use App\Domains\AI\Eval\EvalPromptVersionResolver;
use App\Domains\AI\Eval\InterpretationEvalRunner;
use App\Domains\AI\Eval\PhotoObservationCaseRunner;
use App\Domains\AI\Services\PromptVersionRepository;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    config([
        'app.env' => 'testing',
        'ai.provider' => 'fake',
        'ai.text_inference.enabled' => true,
        'ai.photo_inference.enabled' => true,
        'ai.eval.output_dir' => 'storage/framework/testing/eval-live-vision',
    ]);
    FakeAiClient::reset();
});

afterEach(function () {
    FakeAiClient::reset();
});

test('live vision room_assessment sends fixture image through FakeAiClient with tokens', function () {
    $captured = null;
    FakeAiClient::respondUsing(function (AiCompletionRequest $request) use (&$captured): array {
        $captured = $request;

        return [
            'room_type' => 'bedroom',
            'room_size_indication' => 'medium',
            'sun_exposure' => 'unknown',
            'glass_amount' => 'average',
            'glazing_type' => 'unknown',
            'room_outlet_status' => 'unknown',
            'extra_overview_needed' => 'complete',
            'detected_subject' => 'room',
            'subject_match' => 'yes',
            'confidence' => 'medium',
            'evidence' => 'The room contains a bed and a dresser, indicating a bedroom.',
            'retake_instruction' => null,
        ];
    });

    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/Eval/fixtures/photo_observations/obs-n44-room-evidence-english.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $result = app(PhotoObservationCaseRunner::class)->run($fixture, 1, liveVision: true, promptVersion: 'v10');

    expect($captured)->toBeInstanceOf(AiCompletionRequest::class)
        ->and($captured->images)->toHaveCount(1)
        ->and($captured->promptVersion)->toBe('room-assessment-v10')
        ->and($captured->prompt)->toContain('uitsluitend in het Nederlands')
        ->and($result['live_vision']['executed'])->toBeTrue()
        ->and($result['status'])->toBe('ok')
        ->and($result['token_usage']['total_tokens'])->toBeInt()
        ->and($result['token_usage']['total_tokens'])->toBeGreaterThan(0)
        ->and($result['token_usage']['estimated_cost_cents'])->toBeInt()
        ->and($result['prompt_versions']['room_assessment'])->toBe('room-assessment-v10')
        ->and($result['prompt_source'])->toBe('live')
        ->and($result['scores']['model_raw']['room_type']['status'])->toBe('correct')
        ->and($result['scores']['model_raw']['claim.english_free_text']['status'])->toBe('wrong')
        ->and($result['scores']['model_raw']['claim.english_free_text']['detail'])->toBe('forbidden_concept_present')
        ->and($result['rejections']['vision_gateway'])->toBeNull();
});

test('live vision proposed-v9 alias loads same NL prompt as live v10', function () {
    $captured = null;
    FakeAiClient::respondUsing(function (AiCompletionRequest $request) use (&$captured): array {
        $captured = $request;

        return [
            'room_type' => 'bedroom',
            'room_size_indication' => 'medium',
            'sun_exposure' => 'unknown',
            'glass_amount' => 'little',
            'glazing_type' => 'unknown',
            'room_outlet_status' => 'unknown',
            'extra_overview_needed' => 'complete',
            'detected_subject' => 'room',
            'subject_match' => 'yes',
            'confidence' => 'medium',
            'evidence' => 'De ruimte bevat een bed en een kast; dit lijkt een slaapkamer.',
            'retake_instruction' => null,
        ];
    });

    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/Eval/fixtures/photo_observations/obs-n44-room-evidence-dutch-control.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $result = app(PhotoObservationCaseRunner::class)->run(
        $fixture,
        1,
        liveVision: true,
        promptVersion: 'proposed-v9',
    );

    expect($captured)->toBeInstanceOf(AiCompletionRequest::class)
        ->and($captured->promptVersion)->toBe('room-assessment-v10')
        ->and($captured->prompt)->toContain('uitsluitend in het Nederlands')
        ->and($captured->prompt)->toContain('Nederlands')
        ->and($result['prompt_versions']['room_assessment'])->toBe('room-assessment-v10')
        ->and($result['prompt_source'])->toBe('proposed')
        ->and($result['scores']['model_raw']['claim.english_free_text']['status'])->toBe('correct')
        ->and(app(PromptVersionRepository::class)->version('room_assessment'))->toBe('room-assessment-v10');
});

test('live vision outdoor fixture stays stub until prompt is enabled', function () {
    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/Eval/fixtures/photo_observations/obs-n44-outdoor-evidence-english-risk.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $result = app(PhotoObservationCaseRunner::class)->run($fixture, 1, liveVision: true);

    expect($result['live_vision']['executed'])->toBeFalse()
        ->and($result['live_vision']['reason'])->toBe('prompt_not_yet_supported')
        ->and($result['scores']['model_raw']['claim.english_free_text']['status'])->toBe('wrong')
        ->and($result['token_usage'] ?? null)->toBeNull();
});

test('without live-vision stub path unchanged for N44 english fixture', function () {
    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/Eval/fixtures/photo_observations/obs-n44-room-evidence-english.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $result = app(PhotoObservationCaseRunner::class)->run($fixture, 1);

    expect($result['live_vision']['executed'] ?? false)->toBeFalse()
        ->and($result['scores']['model_raw']['claim.english_free_text']['status'])->toBe('wrong')
        ->and($result['image_present'])->toBeTrue()
        ->and(array_key_exists('token_usage', $result))->toBeFalse();
});

test('eval:interpretation --fake --live-vision --kind=photo_observation writes report with vision cases', function () {
    // Note: EvalRuntimeBootstrap::activateFake() resets FakeAiClient; default
    // room-assessment fake response (NL evidence) is enough to assert wiring.
    $tmp = storage_path('framework/testing/eval-lv-'.uniqid('', true));
    File::ensureDirectoryExists($tmp);

    $exit = Artisan::call('eval:interpretation', [
        '--fake' => true,
        '--repeats' => 1,
        '--kind' => 'photo_observation',
        '--live-vision' => true,
        '--prompt-version' => 'v10',
        '--output' => $tmp,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Live vision AAN')
        ->and($output)->toContain('GEEN baseline')
        ->and($output)->toContain('live_vision executed:');

    $jsonFiles = glob($tmp.'/results/*.json') ?: [];
    expect($jsonFiles)->not->toBeEmpty();

    $report = json_decode((string) file_get_contents($jsonFiles[0]), true);
    expect($report)->toBeArray()
        ->and($report['live_vision'])->toBeTrue()
        ->and($report['prompt_version_selector'])->toBe('v10');

    $executedIds = [];
    foreach ($report['cases'] as $case) {
        if (($case['live_vision']['executed'] ?? false) === true) {
            $executedIds[] = $case['id'];
            expect($case)->toHaveKey('token_usage')
                ->and($case['token_usage']['total_tokens'])->toBeInt();
        }
    }

    expect($executedIds)->toContain('obs-n44-room-evidence-english')
        ->and($executedIds)->toContain('obs-n44-room-evidence-dutch-control')
        ->and($executedIds)->toContain('obs-n44-livingroom-dutch-control')
        ->and($executedIds)->not->toContain('obs-n44-outdoor-evidence-english-risk');

    File::deleteDirectory($tmp);
});

test('EvalPromptVersionResolver rejects unknown selector', function () {
    $resolver = app(EvalPromptVersionResolver::class);

    expect(fn () => $resolver->resolve('room_assessment', 'proposed-v99'))
        ->toThrow(InvalidArgumentException::class);
});

test('EvalPhotoVisionInvoker lists only room_assessment as supported today', function () {
    $invoker = app(EvalPhotoVisionInvoker::class);

    expect($invoker->supports('room_assessment'))->toBeTrue()
        ->and($invoker->supports('outdoor_assessment'))->toBeFalse()
        ->and($invoker->supports('fusebox_assessment'))->toBeFalse()
        ->and($invoker->supports('pipe_route_assessment'))->toBeFalse();
});

test('interpretation eval runner passes liveVision flag into photo cases', function () {
    FakeAiClient::respondUsing(fn (): array => [
        'room_type' => 'living_room',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'unknown',
        'glass_amount' => 'average',
        'glazing_type' => 'unknown',
        'room_outlet_status' => 'present',
        'extra_overview_needed' => 'complete',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Fictieve Nederlandse woonkameranalyse.',
        'retake_instruction' => null,
    ]);

    $tmp = storage_path('framework/testing/eval-lv-runner-'.uniqid('', true));
    config(['ai.eval.output_dir' => $tmp]);

    $result = app(InterpretationEvalRunner::class)->run(
        repeats: 1,
        forceFake: true,
        kinds: ['photo_observation'],
        liveVision: true,
        promptVersion: 'proposed-v9',
    );

    expect($result['report']['live_vision'])->toBeTrue()
        ->and($result['report']['prompt_version_selector'])->toBe('proposed-v9');

    $liveCases = array_values(array_filter(
        $result['report']['cases'],
        static fn (array $c): bool => ($c['live_vision']['executed'] ?? false) === true,
    ));
    expect($liveCases)->not->toBeEmpty();
    foreach ($liveCases as $case) {
        expect($case['prompt_versions']['room_assessment'] ?? null)->toBe('room-assessment-v10')
            ->and($case['prompt_source'])->toBe('proposed');
    }

    File::deleteDirectory($tmp);
});
