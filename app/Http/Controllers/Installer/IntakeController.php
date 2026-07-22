<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

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
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\ExternalFactPresenter;
use App\Domains\Intake\Services\InstallerPhotoGalleryBuilder;
use App\Domains\Intake\Services\IntakeDossierSummaryBuilder;
use App\Domains\Intake\Services\RebuildIntakeReportHtml;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\CustomerLinkMailResult;
use App\Enums\ReviewDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Installer\StoreIntakeRequest;
use App\Http\Requests\Installer\StoreIntakeReviewRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IntakeController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Intake::class);

        $templates = IntakeTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('installer.intakes.create', [
            'templates' => $templates,
            // BL-016: questions the installer may optionally pre-answer, per template.
            'prefillQuestionsByTemplate' => $this->prefillQuestionsByTemplate($templates),
        ]);
    }

    /**
     * @param  Collection<int, IntakeTemplate>  $templates
     * @return array<string, Collection<int, IntakeQuestion>>
     */
    private function prefillQuestionsByTemplate($templates): array
    {
        $byTemplate = [];

        foreach ($templates as $template) {
            $version = $template->latestPublishedVersion();

            if ($version === null) {
                continue;
            }

            $version->loadMissing('sections.questions.options');

            $questions = $version->sections
                ->sortBy('sort_order')
                ->flatMap(fn ($section) => $section->questions->sortBy('sort_order'))
                ->filter(fn ($question) => ($question->meta['installer_prefillable'] ?? false) === true)
                ->values();

            if ($questions->isNotEmpty()) {
                $byTemplate[$template->key] = $questions;
            }
        }

        return $byTemplate;
    }

    public function store(
        StoreIntakeRequest $request,
        CreateIntake $createIntake,
        EnrichIntakeAddress $enrichIntakeAddress,
        SendCustomerIntakeLink $sendCustomerIntakeLink,
    ): RedirectResponse {
        $intake = $createIntake->handle($request->user(), $request->validated());
        $enrichIntakeAddress->handle($intake, $request->validated('address_lookup_id'));
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
            'pipeRouteSessions.segments.upload',
            'pipeRouteSessions.approver',
        ]);

        return view('installer.intakes.show', [
            'intake' => $intake,
            'photoGroups' => $photoGalleryBuilder->handle($intake),
            'externalData' => $externalFactPresenter->present($intake),
            'dossierSummary' => $summaryBuilder->build($intake, $intake->templateVersion),
            'reviewDecisions' => collect(ReviewDecision::cases())
                ->reject(static fn (ReviewDecision $decision): bool => $decision === ReviewDecision::Pending)
                ->values(),
        ]);
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

    public function suggestAttention(Intake $intake, SuggestAttentionPoints $suggestAttentionPoints): RedirectResponse
    {
        $this->authorize('update', $intake);

        $suggestAttentionPoints->handle($intake);

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'AI-aandachtspunten voorgesteld. Beoordeel ze hieronder.');
    }

    public function acceptAttention(
        Intake $intake,
        IntakeAttentionPoint $point,
        RebuildIntakeReportHtml $rebuildIntakeReportHtml,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $this->guardAiProposal($intake, $point);

        $point->update(['status' => AttentionPointStatus::Accepted]);
        $rebuildIntakeReportHtml->handle($intake->fresh() ?? $intake);

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'Aandachtspunt overgenomen.');
    }

    public function dismissAttention(Intake $intake, IntakeAttentionPoint $point): RedirectResponse
    {
        $this->authorize('update', $intake);
        $this->guardAiProposal($intake, $point);

        $point->update(['status' => AttentionPointStatus::Dismissed]);

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'AI-voorstel verwijderd.');
    }

    private function guardAiProposal(Intake $intake, IntakeAttentionPoint $point): void
    {
        if ($point->intake_id !== $intake->id
            || $point->source !== AttentionPointSource::Ai
            || $point->status !== AttentionPointStatus::Proposed) {
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
