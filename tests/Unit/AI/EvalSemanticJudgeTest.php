<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\Eval\EvalCostMeter;
use App\Domains\AI\Eval\JudgeCalibrationCaseRunner;
use App\Domains\AI\Eval\JudgeClaimEvaluator;
use App\Domains\AI\Eval\MeteredAiClient;
use App\Domains\AI\Eval\OptionSummaryCaseRunner;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['ai.provider' => 'fake']);
    FakeAiClient::reset();
});

afterEach(function () {
    FakeAiClient::reset();
});

function n90Fixture(string $path): array
{
    return json_decode((string) file_get_contents(base_path('tests/Eval/fixtures/'.$path)), true, flags: JSON_THROW_ON_ERROR);
}

test('N90: kalibratiefixture labelt N85 en N90 voorbeelden met yes/no', function () {
    $fixture = n90Fixture('judge_calibration/jc-n85-n90-calibration.json');
    $byId = collect($fixture['items'])->keyBy('id');

    expect($byId['n90_planned']['expected'])->toBe('yes')
        ->and($byId['n90_not_decided']['expected'])->toBe('no')
        ->and($byId['n85_full_hedged']['expected'])->toBe('yes')
        ->and($byId['n85_not_full']['expected'])->toBe('no');
});

test('N90: kalibratierunner scoort verdict van de judge tegen het label', function () {
    FakeAiClient::respondUsing(function (AiCompletionRequest $request): array {
        $text = (string) ($request->input['texts'][0] ?? '');

        return ['verdict' => str_contains($text, 'gepland') || str_contains($text, 'vol') ? 'yes' : 'no', 'quote' => ''];
    });

    $result = app(JudgeCalibrationCaseRunner::class)->run(n90Fixture('judge_calibration/jc-n85-n90-calibration.json'), 1);
    $scores = $result['scores']['pipeline_final'];

    expect($scores['judge_calibration.n90_planned']['status'])->toBe('correct')
        ->and($scores['judge_calibration.n85_full_plain']['status'])->toBe('correct')
        ->and($scores['judge_calibration.n90_customer_mentions']['status'])->toBe('correct');
});

test('N90: judge-claim is fout bij "yes" en telt als fout (missing) zonder geldig verdict', function () {
    $expected = ['judge_claims' => [['id' => 'drain_plan', 'question' => 'Gepland?', 'forbidden' => true]]];

    FakeAiClient::alwaysReturn(['verdict' => 'yes', 'quote' => 'De condensafvoer is gepland.']);
    $yes = app(JudgeClaimEvaluator::class)->evaluate($expected, ['De condensafvoer is gepland.'], ['dossier_synthesis']);
    expect($yes['scores']['judge.drain_plan']['status'])->toBe('wrong');

    FakeAiClient::alwaysReturn(['verdict' => 'no', 'quote' => '']);
    $no = app(JudgeClaimEvaluator::class)->evaluate($expected, ['Nog niet besloten.'], ['dossier_synthesis']);
    expect($no['scores']['judge.drain_plan']['status'])->toBe('correct');

    FakeAiClient::alwaysReturn(['something' => 'else']);
    $none = app(JudgeClaimEvaluator::class)->evaluate($expected, ['x'], ['dossier_synthesis']);
    expect($none['scores']['judge.drain_plan']['status'])->toBe('missing');
});

test('N90: optie-samenvatting-runner beoordeelt optie- en verbindingsteksten', function () {
    FakeAiClient::respondUsing(function (AiCompletionRequest $request): array {
        if ($request->promptVersion === 'eval-judge-v1') {
            return ['verdict' => 'no', 'quote' => ''];
        }

        return [
            'summary' => 'Drie ruimtes, één buitenunit.',
            'option_proposals' => [[
                'label' => 'Multi-split drie ruimtes',
                'summary' => 'Afvoer bij voorkeur via regenpijp; ter plaatse bevestigen.',
                'connections' => [['label' => 'Condensafvoer', 'segments' => ['Buiten langs gevel'], 'uncertainties' => ['Route nog niet besloten']]],
            ]],
        ];
    });

    $result = app(OptionSummaryCaseRunner::class)->run(n90Fixture('option_summaries/os-n90-drain-preference-not-plan.json'), 1);

    expect($result['texts'])->toContain('Afvoer bij voorkeur via regenpijp; ter plaatse bevestigen.')
        ->and($result['texts'])->toContain('Route nog niet besloten')
        ->and($result['scores']['pipeline_final']['judge.drain_presented_as_plan']['status'])->toBe('correct')
        ->and($result['scores']['pipeline_final']['synthesis_output']['status'])->toBe('correct');
});

test('N90: synthese zonder optie met verbinding telt als fout en wordt niet beoordeeld', function () {
    FakeAiClient::alwaysReturn(['summary' => 'Alleen een samenvatting.', 'option_proposals' => []]);

    $result = app(OptionSummaryCaseRunner::class)->run(n90Fixture('option_summaries/os-n90-drain-preference-not-plan.json'), 1);

    expect($result['scores']['pipeline_final']['synthesis_output']['status'])->toBe('wrong')
        ->and($result['scores']['pipeline_final'])->not->toHaveKey('judge.drain_presented_as_plan')
        ->and($result['judge'])->toBe([]);
});

test('N90: eval-kostenmeter telt elke AI-call precies één keer aan de clientgrens', function () {
    $meter = new EvalCostMeter;
    $inner = new class implements AiClientInterface
    {
        public function complete(AiCompletionRequest $request): AiCompletionResult
        {
            return new AiCompletionResult(output: [], provider: 'openai', estimatedCost: $request->promptVersion === 'free' ? null : '0.00125');
        }
    };
    $client = new MeteredAiClient($inner, $meter);

    $client->complete(new AiCompletionRequest(prompt: 'p', input: [], promptVersion: 'summary-v9'));
    $client->complete(new AiCompletionRequest(prompt: 'p', input: [], promptVersion: 'eval-judge-v1'));
    $client->complete(new AiCompletionRequest(prompt: 'p', input: [], promptVersion: 'free'));

    expect($meter->summary())->toMatchArray([
        'total_usd' => 0.0025,
        'calls' => 3,
        'calls_with_cost' => 2,
    ]);
});
