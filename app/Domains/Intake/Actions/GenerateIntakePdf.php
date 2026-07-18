<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GenerateIntakePdf
{
    public function handle(Intake $intake): ?GeneratedReport
    {
        $intake->loadMissing('report');
        $report = $intake->report;

        if ($report === null || blank($report->html)) {
            return null;
        }

        $disk = (string) config('filesystems.media', 'local');
        $path = 'intakes/'.$intake->uuid.'/reports/rapport.pdf';

        try {
            $binary = Pdf::loadHTML($report->html)
                ->setPaper('a4')
                ->output();
        } catch (Throwable $exception) {
            Log::warning('Failed to generate intake PDF', [
                'intake_id' => $intake->id,
                'exception' => $exception::class,
            ]);

            return null;
        }

        $this->deleteExistingPdf($report);

        Storage::disk($disk)->put($path, $binary);

        $report->update([
            'pdf_disk' => $disk,
            'pdf_path' => $path,
            'pdf_generated_at' => now(),
        ]);

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'system',
            'actor_id' => null,
            'event' => 'report_pdf_generated',
            'properties' => [
                'disk' => $disk,
            ],
            'created_at' => now(),
        ]);

        return $report->fresh() ?? $report;
    }

    private function deleteExistingPdf(GeneratedReport $report): void
    {
        if (blank($report->pdf_disk) || blank($report->pdf_path)) {
            return;
        }

        try {
            Storage::disk($report->pdf_disk)->delete($report->pdf_path);
        } catch (Throwable) {
            // Best-effort cleanup before overwrite.
        }
    }
}
