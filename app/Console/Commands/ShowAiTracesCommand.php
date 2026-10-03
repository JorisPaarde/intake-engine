<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Models\AiTrace;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use Illuminate\Console\Command;

/**
 * CLI-inzage in AI-traces voor beheerders (lokaal/staging). Geen secrets in output.
 */
final class ShowAiTracesCommand extends Command
{
    protected $signature = 'ai:traces
        {--intake= : Filter op intake-ID}
        {--trace= : Filter op trace UUID}
        {--type= : call_type filter}
        {--status= : status filter}
        {--limit=20 : Maximaal aantal rijen}';

    protected $description = 'Toon AI-traces (request/response-keten) zonder secrets';

    public function handle(): int
    {
        $query = AiTrace::query()->with('steps')->latest('id');

        if (is_numeric($this->option('intake'))) {
            $query->where('intake_id', (int) $this->option('intake'));
        }

        if (is_string($this->option('trace')) && trim((string) $this->option('trace')) !== '') {
            $query->where('trace_id', trim((string) $this->option('trace')));
        }

        if (AiTraceCallType::tryFrom((string) $this->option('type'))) {
            $query->where('call_type', (string) $this->option('type'));
        }

        if (AiTraceStatus::tryFrom((string) $this->option('status'))) {
            $query->where('status', (string) $this->option('status'));
        }

        $limit = max(1, min(100, (int) $this->option('limit')));
        $traces = $query->limit($limit)->get();

        if ($traces->isEmpty()) {
            $this->warn('Geen AI-traces gevonden.');

            return self::SUCCESS;
        }

        $groups = $traces->groupBy(
            static fn (AiTrace $trace): string => $trace->correlation_id ?: ('solo-'.$trace->id),
        );

        foreach ($groups as $correlationId => $group) {
            $this->line(str_repeat('=', 72));
            if ($group->count() > 1 || ! str_starts_with((string) $correlationId, 'solo-')) {
                $this->info('correlation_id='.(str_starts_with((string) $correlationId, 'solo-') ? '—' : $correlationId)
                    .' ('.$group->count().' traces)');
            }

            foreach ($group as $trace) {
                $this->line(str_repeat('-', 40));
                $this->info("trace_id={$trace->trace_id} intake={$trace->intake_id} type={$trace->call_type->value} status={$trace->status->value}");
                $this->line("provider={$trace->provider} model={$trace->model} prompt={$trace->prompt_version}");
                if ($trace->parent_trace_id) {
                    $this->line('parent_trace_id='.$trace->parent_trace_id);
                }
                $this->line(sprintf(
                    'timings: persist=%s network=%s preprocess=%s provider=%s process=%s ms',
                    $trace->persist_ms ?? '—',
                    $trace->network_upload_ms ?? '—',
                    $trace->preprocess_ms ?? '—',
                    $trace->provider_ms ?? '—',
                    $trace->process_ms ?? '—',
                ));
                $this->line('finish_reason='.($trace->finish_reason ?? '—').' tokens='.($trace->total_tokens ?? '—'));
                if ($trace->error_message) {
                    $this->error($trace->error_message);
                }
                $this->line('steps: '.$trace->steps->pluck('step_key')->implode(' → '));
                if (is_array($trace->field_outcomes)) {
                    $this->line('field_outcomes: '.count($trace->field_outcomes));
                }
                if (is_array($trace->request_snapshot)) {
                    $encoded = (string) json_encode($trace->request_snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->line('request_snapshot: '.mb_substr($encoded, 0, 400).(mb_strlen($encoded) > 400 ? '…' : ''));
                }
                if (is_string($trace->raw_response)) {
                    $this->line('raw_response: '.mb_substr($trace->raw_response, 0, 400).(mb_strlen($trace->raw_response) > 400 ? '…' : ''));
                }
            }
        }

        return self::SUCCESS;
    }
}
