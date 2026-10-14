<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\AI\Services\SummaryDossierContext;
use App\Domains\Intake\Jobs\GenerateIntakePdfJob;
use App\Domains\Intake\Models\Intake;

/**
 * Rebuilds the stored report HTML and refreshes the PDF without letting
 * report failures break the surrounding installer write (note/review/option).
 */
class RefreshStoredIntakeReport
{
    public function __construct(
        private readonly RebuildIntakeReportHtml $rebuildIntakeReportHtml,
        private readonly SummaryDossierContext $summaryDossierContext,
    ) {}

    public function handle(Intake $intake): void
    {
        try {
            $this->rebuildIntakeReportHtml->handle($intake);
            // N69: installer decisions change the dossier; refresh the AI proposal when it is stale.
            $this->summaryDossierContext->dispatchIfStale($intake);
            if (! $intake->is_demo) {
                GenerateIntakePdfJob::dispatch($intake->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
