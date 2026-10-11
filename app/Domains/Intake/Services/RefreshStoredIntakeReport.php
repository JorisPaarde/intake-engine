<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

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
    ) {}

    public function handle(Intake $intake): void
    {
        try {
            $this->rebuildIntakeReportHtml->handle($intake);
            if (! $intake->is_demo) {
                GenerateIntakePdfJob::dispatch($intake->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
