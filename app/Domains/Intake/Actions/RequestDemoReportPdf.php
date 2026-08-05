<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Mail\DemoReportPdfMail;
use App\Mail\ProductInterestReceivedMail;
use App\Models\ProductInterest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Opt-in demo conversion: generate the dossier PDF, e-mail it to the prospect,
 * and store/forward the address as a product lead (BL-051).
 */
final class RequestDemoReportPdf
{
    public const LEAD_MARKER = 'source=demo_pdf_request';

    public function __construct(
        private readonly CompletenessChecker $completenessChecker,
        private readonly GenerateIntakeReportHtml $generateIntakeReportHtml,
        private readonly GenerateIntakePdf $generateIntakePdf,
    ) {}

    /**
     * @return array{interest: ProductInterest, report: GeneratedReport, emailed: bool}
     */
    public function handle(Intake $intake, User $installer, string $email): array
    {
        if (! $intake->is_demo) {
            throw ValidationException::withMessages([
                'email' => 'Alleen een demo-opname kan dit demorapport aanvragen.',
            ]);
        }

        if ((int) $intake->company_id !== (int) $installer->company_id) {
            throw ValidationException::withMessages([
                'email' => 'Dit demorapport hoort bij een andere sessie.',
            ]);
        }

        $email = strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'email' => 'Vul een geldig e-mailadres in.',
            ]);
        }

        $installer->loadMissing('company');

        $report = $this->ensureReportHtml($intake);
        $generated = $this->generateIntakePdf->handle($intake->fresh(['report']) ?? $intake);

        if ($generated === null || ! $generated->hasPdf()) {
            throw ValidationException::withMessages([
                'email' => 'Het demorapport kon niet als PDF worden gemaakt. Probeer het later opnieuw.',
            ]);
        }

        $interest = $this->storeLead($intake, $installer, $email);
        $emailed = $this->queueMails($interest, $intake, $generated);

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'user',
            'actor_id' => $installer->id,
            'event' => 'demo_report_pdf_requested',
            'properties' => [
                'product_interest_id' => $interest->id,
                'emailed' => $emailed,
            ],
            'created_at' => now(),
        ]);

        return [
            'interest' => $interest,
            'report' => $generated,
            'emailed' => $emailed,
        ];
    }

    private function ensureReportHtml(Intake $intake): GeneratedReport
    {
        $version = $intake->templateVersion()
            ->with(['sections.questions.options', 'sections.questions.rules', 'template'])
            ->firstOrFail();

        $check = $this->completenessChecker->check($intake, $version);
        $existingMeta = is_array($intake->report?->meta) ? $intake->report->meta : [];
        $aiSummary = is_array($existingMeta['ai_summary'] ?? null) ? $existingMeta['ai_summary'] : null;

        $html = $this->generateIntakeReportHtml->handle(
            $intake,
            $version,
            $check['attention_points'],
            $aiSummary,
        );

        return GeneratedReport::query()->updateOrCreate(
            ['intake_id' => $intake->id],
            [
                'html' => $html,
                'meta' => [
                    ...$existingMeta,
                    'attention_point_codes' => array_column($check['attention_points'], 'code'),
                    'template_version' => $version->version,
                    'demo_pdf_request' => true,
                ],
                'generated_at' => now(),
            ],
        );
    }

    private function storeLead(Intake $intake, User $installer, string $email): ProductInterest
    {
        $retentionDays = max(1, (int) config('intake.interest.retention_days', 365));
        $companyName = trim((string) ($installer->company?->name ?: 'Demo PDF-aanvraag'));

        return ProductInterest::query()->create([
            'company_name' => $companyName !== '' ? $companyName : 'Demo PDF-aanvraag',
            'contact_name' => 'Demo PDF-aanvraag',
            'email' => $email,
            'phone' => null,
            'message' => self::LEAD_MARKER
                ."\n"
                .'Aangevraagd via de publieke installateursdemo.'
                ."\n"
                .'Demo-opname: '.$intake->uuid,
            'expires_at' => now()->addDays($retentionDays),
        ]);
    }

    private function queueMails(ProductInterest $interest, Intake $intake, GeneratedReport $report): bool
    {
        if (config('mail.default') === 'log') {
            return false;
        }

        $leadRecipient = trim((string) config('intake.interest.recipient', 'info@jpwebcreation.nl'));
        $queuedAny = false;

        if ($leadRecipient !== '' && filter_var($leadRecipient, FILTER_VALIDATE_EMAIL) !== false) {
            try {
                Mail::to($leadRecipient)->queue(new ProductInterestReceivedMail($interest));
                $interest->forceFill(['notification_queued_at' => now()])->save();
                $queuedAny = true;
            } catch (Throwable $exception) {
                Log::warning('Failed to queue demo PDF lead notification', [
                    'product_interest_id' => $interest->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        $disk = (string) $report->pdf_disk;
        $path = (string) $report->pdf_path;

        if ($disk === '' || $path === '' || ! Storage::disk($disk)->exists($path)) {
            return $queuedAny;
        }

        try {
            Mail::to($interest->email)->queue(new DemoReportPdfMail(
                email: $interest->email,
                intakeUuid: (string) $intake->uuid,
                pdfDisk: $disk,
                pdfPath: $path,
            ));
            $queuedAny = true;
        } catch (Throwable $exception) {
            Log::warning('Failed to queue demo report PDF mail', [
                'intake_uuid' => $intake->uuid,
                'exception' => $exception::class,
            ]);
        }

        return $queuedAny;
    }
}
