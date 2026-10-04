<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiTrace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds masked AI-trace export payloads (jsonl / markdown) with size-based splitting.
 */
final class AiTraceExporter
{
    /** Soft size budget ≈ 1 MiB or ≈ 200k tokens @ 4 chars/token. */
    public const MAX_PART_BYTES = 1_048_576;

    public const MAX_PART_CHARS = 800_000; // 200_000 tokens * 4

    public function maxPartBytes(): int
    {
        return max(1024, (int) config('ai.tracing.export_max_part_bytes', self::MAX_PART_BYTES));
    }

    public function maxPartChars(): int
    {
        return max(1024, (int) config('ai.tracing.export_max_part_chars', self::MAX_PART_CHARS));
    }

    public function __construct(
        private readonly AiTraceRedactor $redactor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function callPayload(AiTrace $trace): array
    {
        $redactor = $this->redactorFor($trace);
        $requestSnapshot = is_array($trace->request_snapshot)
            ? $redactor->redact($trace->request_snapshot)
            : $trace->request_snapshot;
        $photoRefs = is_array($trace->photo_refs)
            ? $redactor->redact(['refs' => $trace->photo_refs])['refs'] ?? []
            : [];
        $parsed = is_array($trace->parsed_response)
            ? $redactor->redact($trace->parsed_response)
            : $trace->parsed_response;
        $validationErrors = is_array($trace->validation_errors)
            ? $redactor->redact($trace->validation_errors)
            : $trace->validation_errors;
        $normalizations = is_array($trace->normalizations)
            ? ($redactor->redact(['items' => $trace->normalizations])['items'] ?? $trace->normalizations)
            : $trace->normalizations;
        $raw = is_string($trace->raw_response)
            ? $redactor->redactString($trace->raw_response)
            : $trace->raw_response;

        $startedAt = $trace->started_at;
        $finishedAt = $trace->finished_at;

        return [
            'intake_id' => $trace->intake_ref_id ?? $trace->intake_id,
            'intake_ref_id' => $trace->intake_ref_id ?? $trace->intake_id,
            'is_demo' => (bool) $trace->is_demo,
            'trace_id' => $trace->trace_id,
            'call_type' => $trace->call_type->value,
            'status' => $trace->status->value,
            'prompt_version' => $trace->prompt_version,
            'provider' => $trace->provider,
            'model' => $trace->model,
            'request_snapshot' => $requestSnapshot,
            'photo_refs' => $photoRefs,
            'raw_response' => $raw,
            'parsed_response' => $parsed,
            'validation_errors' => $validationErrors,
            'normalizations' => $normalizations,
            'input_tokens' => $trace->input_tokens,
            'output_tokens' => $trace->output_tokens,
            'total_tokens' => $trace->total_tokens,
            'provider_ms' => $trace->provider_ms,
            'process_ms' => $trace->process_ms,
            'persist_ms' => $trace->persist_ms,
            'preprocess_ms' => $trace->preprocess_ms,
            'network_upload_ms' => $trace->network_upload_ms,
            'queue_wait_ms' => $trace->queue_wait_ms,
            'queued_at' => $trace->queued_at instanceof Carbon ? $trace->queued_at->toIso8601String() : null,
            'estimated_cost_cents' => $trace->estimated_cost_cents,
            'estimated_cost' => $trace->estimated_cost,
            'error_message' => is_string($trace->error_message)
                ? $redactor->redactString($trace->error_message)
                : $trace->error_message,
            'request_id' => $trace->request_id,
            'provider_response_id' => $trace->provider_response_id,
            'correlation_id' => $trace->correlation_id,
            'attempt' => $trace->attempt,
            'retry_count' => $trace->retry_count,
            'finish_reason' => $trace->finish_reason,
            'model_parameters' => $trace->model_parameters,
            'started_at' => $startedAt instanceof Carbon ? $startedAt->toIso8601String() : null,
            'finished_at' => $finishedAt instanceof Carbon ? $finishedAt->toIso8601String() : null,
        ];
    }

    /**
     * @param  Collection<int, AiTrace>  $traces
     * @return list<array{intake_ids: list<int>, traces: list<AiTrace>, approx_bytes: int}>
     */
    public function splitByBudget(Collection $traces): array
    {
        /** @var array<int, list<AiTrace>> $byIntake */
        $byIntake = [];
        foreach ($traces as $trace) {
            $intakeId = (int) ($trace->intake_ref_id ?? $trace->intake_id ?? 0);
            $byIntake[$intakeId][] = $trace;
        }
        ksort($byIntake);

        $parts = [];
        $current = ['intake_ids' => [], 'traces' => [], 'approx_bytes' => 0];

        foreach ($byIntake as $intakeId => $intakeTraces) {
            $intakeBytes = 0;
            foreach ($intakeTraces as $trace) {
                $intakeBytes += strlen((string) json_encode($this->callPayload($trace), JSON_UNESCAPED_UNICODE));
            }

            $wouldExceed = $current['traces'] !== []
                && ($this->maxPartBytes() < $current['approx_bytes'] + $intakeBytes
                    || $this->maxPartChars() < $current['approx_bytes'] + $intakeBytes);

            if ($wouldExceed) {
                $parts[] = $current;
                $current = ['intake_ids' => [], 'traces' => [], 'approx_bytes' => 0];
            }

            if ($intakeBytes > $this->maxPartBytes() || $intakeBytes > $this->maxPartChars()) {
                // Single intake too large → split on call boundaries.
                $callBucket = ['intake_ids' => [$intakeId], 'traces' => [], 'approx_bytes' => 0];
                foreach ($intakeTraces as $trace) {
                    $callBytes = strlen((string) json_encode($this->callPayload($trace), JSON_UNESCAPED_UNICODE));
                    if ($callBucket['traces'] !== []
                        && ($this->maxPartBytes() < $callBucket['approx_bytes'] + $callBytes
                            || $this->maxPartChars() < $callBucket['approx_bytes'] + $callBytes)) {
                        $parts[] = $callBucket;
                        $callBucket = ['intake_ids' => [$intakeId], 'traces' => [], 'approx_bytes' => 0];
                    }
                    $callBucket['traces'][] = $trace;
                    $callBucket['approx_bytes'] += $callBytes;
                }
                if ($callBucket['traces'] !== []) {
                    if ($current['traces'] === []) {
                        $current = $callBucket;
                    } else {
                        $parts[] = $current;
                        $current = $callBucket;
                    }
                }

                continue;
            }

            $current['intake_ids'][] = $intakeId;
            foreach ($intakeTraces as $trace) {
                $current['traces'][] = $trace;
            }
            $current['approx_bytes'] += $intakeBytes;
        }

        if ($current['traces'] !== []) {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * @param  list<AiTrace>  $traces
     */
    public function renderJsonl(array $traces): string
    {
        $lines = [];
        foreach ($traces as $trace) {
            $lines[] = (string) json_encode($this->callPayload($trace), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines).($lines === [] ? '' : "\n");
    }

    /**
     * @param  list<AiTrace>  $traces
     * @param  array{part?: int, parts?: int, intake_ids_in_part?: list<int>, total_intakes?: int, total_calls?: int, total_cost_cents?: int}|null  $meta
     */
    public function renderMarkdown(array $traces, ?array $meta = null): string
    {
        /** @var array<int, list<AiTrace>> $byIntake */
        $byIntake = [];
        $models = [];
        $totalCost = 0;
        foreach ($traces as $trace) {
            $intakeId = (int) ($trace->intake_ref_id ?? $trace->intake_id ?? 0);
            $byIntake[$intakeId][] = $trace;
            if (is_string($trace->model) && $trace->model !== '') {
                $models[$trace->model] = true;
            }
            $totalCost += (int) ($trace->estimated_cost_cents ?? 0);
        }
        ksort($byIntake);

        $intakeCount = count($byIntake);
        $callCount = count($traces);
        $lines = [];
        $lines[] = '# AI-trace export';
        $lines[] = '';
        $lines[] = '## Index';
        $lines[] = '';
        $lines[] = '- Intakes: '.$intakeCount.(($meta['total_intakes'] ?? null) !== null ? ' (totaal run: '.$meta['total_intakes'].')' : '');
        $lines[] = '- Calls: '.$callCount.(($meta['total_calls'] ?? null) !== null ? ' (totaal run: '.$meta['total_calls'].')' : '');
        $lines[] = '- Totale geschatte kosten (centen): '.$totalCost.(($meta['total_cost_cents'] ?? null) !== null ? ' (totaal run: '.$meta['total_cost_cents'].')' : '');
        if (isset($meta['part'], $meta['parts'])) {
            $lines[] = '- Part: '.$meta['part'].' van '.$meta['parts'];
            $lines[] = '- Intakes in dit part: '.implode(', ', $meta['intake_ids_in_part'] ?? array_keys($byIntake));
        }
        $lines[] = '- Models: '.($models === [] ? '—' : implode(', ', array_keys($models)));
        $lines[] = '';

        foreach ($byIntake as $intakeId => $intakeTraces) {
            $first = $intakeTraces[0];
            $intakeModels = [];
            foreach ($intakeTraces as $trace) {
                if (is_string($trace->model) && $trace->model !== '') {
                    $intakeModels[$trace->model] = true;
                }
            }
            $startedAt = $first->started_at;
            $createdAt = $first->created_at;
            $date = $startedAt instanceof Carbon
                ? $startedAt->toDateString()
                : ($createdAt instanceof Carbon ? $createdAt->toDateString() : 'onbekend');
            $lines[] = '## Intake '.$intakeId;
            $lines[] = '';
            $lines[] = '- Demo: '.((bool) $first->is_demo ? 'ja' : 'nee');
            $lines[] = '- Datum: '.$date;
            $lines[] = '- Models: '.($intakeModels === [] ? '—' : implode(', ', array_keys($intakeModels)));
            $lines[] = '- Calls: '.count($intakeTraces);
            $lines[] = '';

            foreach ($intakeTraces as $index => $trace) {
                $n = $index + 1;
                $lines[] = '### Call '.$n.': '.$trace->call_type->value;
                $lines[] = '';
                $lines[] = '```json';
                $lines[] = (string) json_encode($this->callPayload($trace), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $lines[] = '```';
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    private function redactorFor(AiTrace $trace): AiTraceRedactor
    {
        // Re-apply masking as a safety net; known intake PII may be gone after demo purge.
        return $this->redactor;
    }
}
