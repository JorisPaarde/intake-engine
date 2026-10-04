<?php

declare(strict_types=1);

namespace App\Http\Controllers\E2e;

use App\Domains\AI\Support\E2eAiScenario;
use App\Domains\Intake\Models\Intake;
use App\Support\E2e\E2eScenarioFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class E2eHelperController
{
    public function health(): JsonResponse
    {
        $this->guard();

        return response()->json([
            'ok' => true,
            'ai_provider' => config('ai.provider'),
            'ai_scenario' => E2eAiScenario::get(),
            'queue' => config('queue.default'),
        ]);
    }

    public function createScenario(Request $request, E2eScenarioFactory $factory, string $scenario): JsonResponse
    {
        $this->guard();

        return response()->json($factory->create($scenario));
    }

    public function setAiScenario(Request $request): JsonResponse
    {
        $this->guard();

        $scenario = (string) $request->input('scenario', E2eAiScenario::GOOD_PHOTO);
        E2eAiScenario::set($scenario);

        return response()->json(['ai_scenario' => E2eAiScenario::get()]);
    }

    public function uploads(int $intakeId, E2eScenarioFactory $factory): JsonResponse
    {
        $this->guard();

        $intake = Intake::query()->findOrFail($intakeId);

        return response()->json([
            'intake_id' => $intake->id,
            'uploads' => $factory->uploadSummaries($intake),
        ]);
    }

    private function guard(): void
    {
        if (! (bool) config('ai.e2e_helpers_enabled', false)) {
            throw new NotFoundHttpException;
        }
    }
}
