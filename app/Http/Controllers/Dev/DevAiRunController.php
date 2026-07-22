<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Domains\AI\Models\AiRun;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DevAiRunController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'provider' => ['nullable', 'string'],
        ]);

        $runs = AiRun::query()
            ->with('intake')
            ->when(
                AiRunType::tryFrom((string) ($validated['type'] ?? '')),
                fn ($query, AiRunType $type) => $query->where('type', $type),
            )
            ->when(
                AiRunStatus::tryFrom((string) ($validated['status'] ?? '')),
                fn ($query, AiRunStatus $status) => $query->where('status', $status),
            )
            ->when(
                trim((string) ($validated['provider'] ?? '')) !== '',
                fn ($query) => $query->where('provider', $validated['provider']),
            )
            ->latest('started_at')
            ->paginate(25)
            ->withQueryString();

        return view('dev.ai-runs', [
            'runs' => $runs,
            'types' => AiRunType::cases(),
            'statuses' => AiRunStatus::cases(),
            'providers' => AiRun::query()->distinct()->orderBy('provider')->pluck('provider'),
            'filters' => $validated,
        ]);
    }
}
