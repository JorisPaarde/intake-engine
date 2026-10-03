<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Domains\AI\Models\AiTrace;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
            'correlation_id' => ['nullable', 'string', 'max:64'],
        ]);

        $baseQuery = AiTrace::query()
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
            ->when(
                trim((string) ($validated['correlation_id'] ?? '')) !== '',
                fn ($query) => $query->where('correlation_id', trim((string) $validated['correlation_id'])),
            );

        $perPage = 20;
        $page = max(1, (int) $request->integer('page', 1));

        $totalGroups = (int) (clone $baseQuery)
            ->selectRaw('count(distinct coalesce(correlation_id, trace_id)) as aggregate')
            ->value('aggregate');

        /** @var Collection<int, string> $groupKeys */
        $groupKeys = (clone $baseQuery)
            ->reorder()
            ->selectRaw('coalesce(correlation_id, trace_id) as group_key')
            ->selectRaw('max(coalesce(started_at, created_at)) as latest_at')
            ->groupByRaw('coalesce(correlation_id, trace_id)')
            ->orderByDesc('latest_at')
            ->forPage($page, $perPage)
            ->pluck('group_key');

        /** @var Collection<string, Collection<int, AiTrace>> $traceGroups */
        $traceGroups = collect();
        if ($groupKeys->isNotEmpty()) {
            $placeholders = implode(',', array_fill(0, $groupKeys->count(), '?'));
            $traces = (clone $baseQuery)
                ->with(['intake', 'steps'])
                ->whereRaw("coalesce(correlation_id, trace_id) in ({$placeholders})", $groupKeys->all())
                ->orderBy('started_at')
                ->get();

            $traceGroups = $traces
                ->groupBy(fn (AiTrace $trace) => $trace->correlation_id ?? $trace->trace_id)
                ->sortByDesc(fn (Collection $group) => (int) $group->max('id'));
        }

        $paginator = new LengthAwarePaginator(
            $traceGroups,
            $totalGroups,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );

        return view('dev.ai-traces', [
            'traceGroups' => $paginator,
            'callTypes' => AiTraceCallType::cases(),
            'statuses' => AiTraceStatus::cases(),
            'filters' => $validated,
        ]);
    }

    public function show(AiTrace $aiTrace): View
    {
        $aiTrace->load(['intake', 'steps', 'aiRun', 'upload']);

        $relatedTraces = collect();
        if ($aiTrace->correlation_id) {
            $relatedTraces = AiTrace::query()
                ->where('correlation_id', $aiTrace->correlation_id)
                ->orderBy('started_at')
                ->get(['id', 'trace_id', 'call_type', 'status', 'parent_trace_id', 'started_at']);
        }

        return view('dev.ai-traces-show', [
            'trace' => $aiTrace,
            'relatedTraces' => $relatedTraces,
        ]);
    }
}
