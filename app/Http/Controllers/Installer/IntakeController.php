<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\EnrichIntakeAddress;
use App\Domains\Intake\Actions\RegenerateIntakeAccessToken;
use App\Domains\Intake\Actions\RevokeIntakeAccess;
use App\Domains\Intake\Actions\SendCustomerFollowUpRequest;
use App\Domains\Intake\Actions\SendCustomerIntakeLink;
use App\Domains\Intake\Actions\SubmitIntakeReview;
use App\Domains\Intake\Jobs\GenerateIntakePdfJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierOverviewBuilder;
use App\Domains\Intake\Services\ExternalFactPresenter;
use App\Domains\Intake\Services\InstallerPhotoGalleryBuilder;
use App\Domains\Intake\Services\IntakeDossierSummaryBuilder;
use App\Domains\Intake\Services\PdokAddressService;
use App\Domains\Intake\Services\PublicDemoSession;
use App\Domains\Intake\Services\RebuildIntakeReportHtml;
use App\Domains\Intake\Services\WorkspacePrimaryActionResolver;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoOptionStatus;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\CustomerLinkMailResult;
use App\Enums\DecisionAreaStatus;
use App\Enums\IntakeStatus;
use App\Enums\PipeRouteStatus;
use App\Enums\ReviewDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Installer\StoreIntakeRequest;
use App\Http\Requests\Installer\StoreIntakeReviewRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IntakeController extends Controller
{
    public function create(Request $request, PublicDemoSession $publicDemoSession): View
    {
        $this->authorize('create', Intake::class);

        $templates = IntakeTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $isPublicDemo = $publicDemoSession->isActive($request);
        $demoDefaults = null;

        $demoAddressExample = null;

        if ($isPublicDemo) {
            // Geen @demo.invalid in value/placeholder — uniek e-mailadres wordt bij opslaan gezet.
            // Naam + postcode/huisnummer typt de installateur zelf (DEMO_* alleen tiptekst).
            $demoDefaults = [
                'internal_note' => 'Fictieve interactieve demo — geen echte klant of offerte.',
            ];
            $demoAddressExample = [
                'line' => (string) config('intake.demo.address.line', 'Bernadottelaan 273'),
                'postal_code' => (string) config('intake.demo.address.postal_code', '2037GR'),
                'house_number' => (int) config('intake.demo.address.house_number', 273),
                'city' => (string) config('intake.demo.address.city', 'Haarlem'),
                'customer_name' => (string) config('intake.demo.customer_name', 'Familie de Vries'),
            ];
        }

        return view('installer.intakes.create', [
            'templates' => $templates,
            'isPublicDemo' => $isPublicDemo,
            'demoDefaults' => $demoDefaults,
            'demoAddressExample' => $demoAddressExample,
        ]);
    }

    public function store(StoreIntakeRequest $request,
        CreateIntake $createIntake,
        DeriveIntentFromRequest $deriveIntentFromRequest,
        EnrichIntakeAddress $enrichIntakeAddress,
        SendCustomerIntakeLink $sendCustomerIntakeLink,
        PublicDemoSession $publicDemoSession,
    ): RedirectResponse {
        $isPublicDemo = $publicDemoSession->isActive($request);
        $payload = $request->validated();

        if ($isPublicDemo) {
            // Workflow is chosen after create via the demo branch modal (no mail).
            $payload['workflow_mode'] = ContributionMode::Customer;
            $payload['is_demo'] = true;
            $payload['token_ttl_hours'] = max(1, (int) config('intake.demo.ttl_hours', 2));
            $payload['customer_access_enabled'] = false;

            $demoReason = trim((string) config(
                'intake.demo.request_reason',
                'Twee slaapkamers op zolder koelen; het wordt daar te warm in de zomer.',
            ));
            if ($demoReason !== '' && blank(data_get($payload, 'prefill.request_reason'))) {
                $payload['prefill'] = [
                    ...(is_array($payload['prefill'] ?? null) ? $payload['prefill'] : []),
                    'request_reason' => $demoReason,
                ];
            }
        }

        $intake = $createIntake->handle($request->user(), $payload);

        // Eerst bronnen verrijken, daarna prefill (ADR-0014): AI ziet BAG/EP-feiten mee.
        $enrichIntakeAddress->handle($intake, $request->validated('address_lookup_id'));
        $deriveIntentFromRequest->handle($intake->fresh() ?? $intake);

        if ($isPublicDemo) {
            // Keep the link ready but inactive until the visitor picks a path.
            $intake->forceFill([
                'customer_access_enabled' => false,
                'status' => IntakeStatus::Draft,
            ])->save();

            $request->session()->put([
                'public_demo_intake_id' => $intake->id,
                'public_demo_guide_step' => 'branch',
                'public_demo_path_chosen' => null,
            ]);

            return redirect()
                ->route('intakes.show', $intake)
                ->with('demo_coachmark', 'branch')
                ->with(
                    'status',
                    'Opname aangemaakt. Adresgegevens zijn opgehaald. Kies hieronder hoe u verder wilt kijken — er gaat geen e-mail uit in de demo.',
                );
        }

        if ($intake->workflow_mode === ContributionMode::Installer) {
            return redirect()
                ->route('intakes.workspace', $intake)
                ->with('status', 'Opname aangemaakt. Er is geen klantlink verstuurd; u kunt direct zelf beginnen.');
        }

        $mailResult = $sendCustomerIntakeLink->handle($intake, $request->user());

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', $mailResult->flashMessage('created'));
    }

    public function show(
        Intake $intake,
        InstallerPhotoGalleryBuilder $photoGalleryBuilder,
        ExternalFactPresenter $externalFactPresenter,
        IntakeDossierSummaryBuilder $summaryBuilder,
        DossierOverviewBuilder $dossierOverviewBuilder,
    ): View {
        $this->authorize('view', $intake);

        $intake->load([
            'templateVersion.template',
            'templateVersion.sections.questions',
            'creator',
            'uploads',
            'answers',
            'attentionPoints',
            'externalFacts',
            'followUpRounds.items.uploads',
            'report',
            'review.reviewer',
            'aircoRooms',
            'aircoPlacements',
            'aircoInstallationOptions.connections',
            'contributionTasks',
            'aiRuns',
        ]);

        $dossier = $dossierOverviewBuilder->build($intake);
        $quoteArea = $dossier['quote'];
        $openAreas = $dossier['blockers'];
        $selectedOption = $intake->aircoInstallationOptions->first(
            static fn ($option) => $option->status === AircoOptionStatus::Selected,
        );
        $proposalAlreadyApproved = $selectedOption
            && in_array($intake->status, [IntakeStatus::Completed, IntakeStatus::Reviewed], true)
            && $selectedOption->connections->isNotEmpty()
            && $selectedOption->connections->every(
                static fn ($connection) => $connection->status === AircoConnectionStatus::Approved
                    && (! $connection->routeSession
                        || $connection->routeSession->status === PipeRouteStatus::Approved),
            );
        $canApproveProposal = ! $proposalAlreadyApproved
            && $selectedOption
            && in_array($quoteArea?->status, [DecisionAreaStatus::Ready, DecisionAreaStatus::Review], true);
        $proposedCustomerTasks = $intake->contributionTasks
            ->where('status', ContributionTaskStatus::Proposed);
        $primaryAction = app(WorkspacePrimaryActionResolver::class)->resolve(
            $intake,
            $quoteArea,
            $canApproveProposal,
            $proposalAlreadyApproved,
            $proposedCustomerTasks,
            $openAreas,
        );
        $aiProvider = (string) config('ai.provider', 'null');
        $attentionAiSucceeded = $intake->aiRuns
            ->where('type', AiRunType::AttentionPoints)
            ->contains(static fn ($run) => $run->status === AiRunStatus::Succeeded);

        return view('installer.intakes.show', [
            'intake' => $intake,
            'dossier' => $dossier,
            'photoGroups' => $photoGalleryBuilder->handle($intake),
            'externalData' => $externalFactPresenter->present($intake),
            'dossierSummary' => $summaryBuilder->build($intake, $intake->templateVersion),
            'reviewDecisions' => collect(ReviewDecision::cases())
                ->reject(static fn (ReviewDecision $decision): bool => $decision === ReviewDecision::Pending)
                ->values(),
            'primaryAction' => $primaryAction,
            'workspaceUrl' => route('intakes.workspace', $intake),
            'aiProvider' => $aiProvider,
            'aiAttentionAvailable' => $aiProvider !== 'null',
            'attentionAiSucceeded' => $attentionAiSucceeded,
        ]);
    }

    public function suggestAttention(
        Intake $intake,
        SuggestAttentionPoints $suggestAttentionPoints,
    ): RedirectResponse {
        $this->authorize('update', $intake);

        if ((string) config('ai.provider', 'null') === 'null') {
            throw new NotFoundHttpException('AI is niet beschikbaar.');
        }

        $suggestAttentionPoints->handle($intake);

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'AI-aandachtspunten bijgewerkt.');
    }

    public function retryAddressEnrichment(
        Intake $intake,
        EnrichIntakeAddress $enrichIntakeAddress,
        DeriveIntentFromRequest $deriveIntentFromRequest,
    ): RedirectResponse {
        $this->authorize('update', $intake);

        $enrichIntakeAddress->handle($intake);
        $deriveIntentFromRequest->handle($intake->fresh() ?? $intake);

        $verification = $intake->externalFacts()
            ->where('fact_key', 'address_verification')
            ->where('source', PdokAddressService::sourceName())
            ->first();
        $status = $verification?->value['status'] ?? null;
        $message = match ($status) {
            'matched' => 'Adres opnieuw gecontroleerd. De BAG-gegevens zijn bijgewerkt.',
            'unavailable' => 'PDOK/BAG is tijdelijk niet beschikbaar. Probeer het later opnieuw.',
            default => 'Het adres kon nog niet eenduidig in de BAG worden gevonden.',
        };

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', $message);
    }

    public function review(
        StoreIntakeReviewRequest $request,
        Intake $intake,
        SubmitIntakeReview $submitIntakeReview,
        SendCustomerFollowUpRequest $sendCustomerFollowUpRequest,
    ): RedirectResponse {
        $review = $submitIntakeReview->handle($intake, $request->user(), $request->validated());

        $message = 'Beoordeling opgeslagen.';

        if ($review->decision === ReviewDecision::NeedMoreInfo) {
            $round = $intake->followUpRounds()->latest('round_number')->firstOrFail();
            $mailResult = $sendCustomerFollowUpRequest->handle($intake->fresh() ?? $intake, $round, $request->user());
            $message = match ($mailResult) {
                CustomerLinkMailResult::Sent => 'Aanvullende vragen opgeslagen en naar de klant gemaild.',
                CustomerLinkMailResult::SkippedLogMailer => 'Aanvullende vragen opgeslagen. Mail is nog niet geconfigureerd; deel de bestaande klantlink handmatig.',
                CustomerLinkMailResult::Failed => 'Aanvullende vragen opgeslagen, maar de e-mail kon niet worden verstuurd. Deel de bestaande klantlink handmatig.',
                default => 'Aanvullende vragen opgeslagen. Deel de bestaande klantlink met de klant.',
            };
        }

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', $message);
    }

    public function acceptAttention(
        Intake $intake,
        IntakeAttentionPoint $point,
        RebuildIntakeReportHtml $rebuildIntakeReportHtml,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $lockedPoint = DB::transaction(function () use ($intake, $point): IntakeAttentionPoint {
            Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $lockedPoint = IntakeAttentionPoint::query()->whereKey($point->id)->lockForUpdate()->firstOrFail();
            $this->guardAiProposal($intake, $lockedPoint, requireProvenance: true);
            $lockedPoint->update(['status' => AttentionPointStatus::Accepted]);

            return $lockedPoint;
        }, 3);
        $rebuildIntakeReportHtml->handle($intake->fresh() ?? $intake);
        GenerateIntakePdfJob::dispatch($intake->id);

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'Aandachtspunt overgenomen.');
    }

    public function dismissAttention(Intake $intake, IntakeAttentionPoint $point): RedirectResponse
    {
        $this->authorize('update', $intake);
        DB::transaction(function () use ($intake, $point): void {
            Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $lockedPoint = IntakeAttentionPoint::query()->whereKey($point->id)->lockForUpdate()->firstOrFail();
            $this->guardAiProposal($intake, $lockedPoint);
            $lockedPoint->update(['status' => AttentionPointStatus::Dismissed]);
        }, 3);

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'AI-voorstel verwijderd.');
    }

    private function guardAiProposal(
        Intake $intake,
        IntakeAttentionPoint $point,
        bool $requireProvenance = false,
    ): void {
        if ($point->intake_id !== $intake->id
            || $point->source !== AttentionPointSource::Ai
            || $point->status !== AttentionPointStatus::Proposed
            || ($requireProvenance && ! $point->hasValidAiProvenance())) {
            throw new NotFoundHttpException('Aandachtspunt niet gevonden.');
        }
    }

    public function revoke(Request $request, Intake $intake, RevokeIntakeAccess $revokeIntakeAccess): RedirectResponse
    {
        $this->authorize('revoke', $intake);

        $revokeIntakeAccess->handle($intake, $request->user());

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'Klantlink ingetrokken en opname geannuleerd.');
    }

    public function regenerateToken(
        Request $request,
        Intake $intake,
        RegenerateIntakeAccessToken $regenerate,
        SendCustomerIntakeLink $sendCustomerIntakeLink,
    ): RedirectResponse {
        $this->authorize('update', $intake);

        if (! $intake->customer_access_enabled) {
            return redirect()
                ->route('intakes.show', $intake)
                ->with('status', 'Geen klantlink actief. Stuur vanuit de opname eerst een taak naar de klant.');
        }

        $intake = $regenerate->handle($intake, $request->user());
        $mailResult = $sendCustomerIntakeLink->handle($intake, $request->user());

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', $mailResult->flashMessage('regenerated'));
    }

    public function sendLink(
        Request $request,
        Intake $intake,
        SendCustomerIntakeLink $sendCustomerIntakeLink,
    ): RedirectResponse {
        $this->authorize('update', $intake);

        if (! $intake->customer_access_enabled) {
            return redirect()
                ->route('intakes.show', $intake)
                ->with('status', 'Geen klantlink actief. Stuur vanuit de opname eerst een taak naar de klant.');
        }

        $mailResult = $sendCustomerIntakeLink->handle($intake, $request->user());

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', $mailResult->flashMessage('resend'));
    }

    public function downloadPdf(Intake $intake): StreamedResponse
    {
        $this->authorize('view', $intake);

        $intake->loadMissing('report');
        $report = $intake->report;

        if ($report === null || ! $report->hasPdf()) {
            throw new NotFoundHttpException('PDF is nog niet beschikbaar.');
        }

        $disk = (string) $report->pdf_disk;
        $path = (string) $report->pdf_path;

        if (! Storage::disk($disk)->exists($path)) {
            throw new NotFoundHttpException('PDF-bestand ontbreekt.');
        }

        $filename = 'opname-'.$intake->uuid.'.pdf';

        return Storage::disk($disk)->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function previewReport(Intake $intake): Response
    {
        $this->authorize('view', $intake);

        $intake->loadMissing('report');
        $report = $intake->report;

        if ($report === null) {
            throw new NotFoundHttpException('Rapport is nog niet beschikbaar.');
        }

        return response($report->html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header(
                'Content-Security-Policy',
                "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'",
            );
    }

    public function regeneratePdf(Intake $intake): RedirectResponse
    {
        $this->authorize('view', $intake);

        $intake->loadMissing('report');

        if ($intake->report === null) {
            return redirect()
                ->route('intakes.show', $intake)
                ->with('status', 'Er is nog geen rapport om als PDF te exporteren.');
        }

        GenerateIntakePdfJob::dispatch($intake->id);

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'PDF-export is in de wachtrij gezet. Vernieuw deze pagina over een moment.');
    }
}
