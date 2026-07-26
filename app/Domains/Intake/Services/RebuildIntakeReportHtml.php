<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Enums\AttentionPointStatus;
use Illuminate\Support\Facades\DB;

/**
 * Regenerates the stored report HTML from the current authoritative attention points
 * while preserving any AI summary already attached (BL-007). Used when the installer
 * accepts/dismisses an AI-proposed attention point after completion.
 */
final class RebuildIntakeReportHtml
{
    public function __construct(
        private readonly GenerateIntakeReportHtml $generateIntakeReportHtml,
    ) {}

    public function handle(Intake $intake): void
    {
        DB::transaction(function () use ($intake): void {
            $intake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $report = $intake->report()->lockForUpdate()->first();

            if ($report === null) {
                return;
            }

            $intake->load('attentionPoints');

            $version = $intake->templateVersion()
                ->with(['sections.questions.options', 'sections.questions.rules', 'template'])
                ->firstOrFail();

            $attentionPoints = $intake->attentionPoints
                ->filter(static fn ($point): bool => $point->status === null
                    || $point->status === AttentionPointStatus::Accepted)
                ->map(static fn ($point): array => [
                    'code' => (string) ($point->code ?? ''),
                    'label' => $point->label,
                ])
                ->values()
                ->all();

            $rawMeta = $report->getAttribute('meta');
            $meta = is_array($rawMeta) ? $rawMeta : [];
            /** @var array{summary: string, highlights: list<string>}|null $aiSummary */
            $aiSummary = is_array($meta['ai_summary'] ?? null) ? $meta['ai_summary'] : null;

            $html = $this->generateIntakeReportHtml->handle($intake, $version, $attentionPoints, $aiSummary);

            $report->update([
                'html' => $html,
                'generated_at' => now(),
            ]);
        }, 3);
    }
}
