<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Domains\AI\Models\AiTrace;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DevAiTraceController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'call_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'intake_id' => ['nullable', 'integer'],
            'trace_id' => ['nullable', 'string', 'max:64'],
        ]);

        $traces = AiTrace::query()
            ->with(['intake', 'steps'])
            ->when(
                AiTraceCallType::tryFrom((string) ($validated['call_type'] ?? '')),
                fn ($query, AiTraceCallType $type) => $query->where('call_type', $type),
            )
            ->when(
                AiTraceStatus::tryFrom((string) ($validated['status'] ?? '')),
                fn ($query, AiTraceStatus $status) => $query->where('status', $status),
            )
            ->when(
                isset($validated['intake_id']),
                fn ($query) => $query->where('intake_id', (int) $validated['intake_id']),
            )
            ->when(
                trim((string) ($validated['trace_id'] ?? '')) !== '',
                fn ($query) => $query->where('trace_id', trim((string) $validated['trace_id'])),
            )
            ->latest('started_at')
            ->paginate(20)
            ->withQueryString();

        return view('dev.ai-traces', [
            'traces' => $traces,
            'callTypes' => AiTraceCallType::cases(),
            'statuses' => AiTraceStatus::cases(),
            'filters' => $validated,
        ]);
    }

    public function show(AiTrace $aiTrace): View
    {
        $aiTrace->load(['intake', 'steps', 'aiRun', 'upload']);

        return view('dev.ai-traces-show', [
            'trace' => $aiTrace,
        ]);
    }
}
