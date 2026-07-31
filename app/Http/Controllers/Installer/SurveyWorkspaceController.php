<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\AI\Actions\SuggestInstallerPhotoObservations;
use App\Domains\AI\Actions\SynthesizePipeRoute;
use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\Intake\Actions\AddPipeRoutePhoto;
use App\Domains\Intake\Actions\ApprovePipeRoute;
use App\Domains\Intake\Actions\CompleteInstallerSurvey;
use App\Domains\Intake\Actions\ConfirmInstallerObservation;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\RecordInstallationOutcome;
use App\Domains\Intake\Actions\SaveInstallerObservation;
use App\Domains\Intake\Actions\SendCustomerFollowUpRequest;
use App\Domains\Intake\Actions\StartPipeRouteSession;
use App\Domains\Intake\Actions\StoreInstallerDossierUpload;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\DossierOverviewBuilder;
use App\Domains\Intake\Services\ExternalFactPresenter;
use App\Domains\Intake\Services\InstallerPhotoGalleryBuilder;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionTaskStatus;
use App\Enums\CustomerLinkMailResult;
use App\Enums\DossierRecordStatus;
use App\Enums\FollowUpItemType;
use App\Enums\InstallationProposalDelta;
use App\Enums\InstallationSiteVisitReason;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SurveyWorkspaceController extends Controller
{
    public function show(
        Intake $intake,
        DossierManager $dossierManager,
        DossierOverviewBuilder $overviewBuilder,
        ExternalFactPresenter $externalFactPresenter,
        InstallerPhotoGalleryBuilder $photoGalleryBuilder,
    ): View {
        $this->authorize('view', $intake);
        $dossierManager->initialize($intake);
        $intake->load([
            'company',
            'dossierSubjects.records.evidenceLinks',
            'dossierSubjects.evidenceLinks',
            'aircoRooms.placements',
            'aircoPlacements.room',
            'aircoInstallationOptions.placements.room',
            'aircoInstallationOptions.connections.fromPlacement',
            'aircoInstallationOptions.connections.toPlacement',
            'aircoInstallationOptions.connections.routeSession.segments.upload',
            'contributionTasks.followUpItem.uploads',
            'contributionTasks.subject',
            'followUpRounds.items.uploads',
            'uploads',
            'outcome',
        ]);

        return view('installer.intakes.workspace', [
            'intake' => $intake,
            'dossier' => $overviewBuilder->build($intake),
            'externalData' => $externalFactPresenter->present($intake),
            'photoGroups' => $photoGalleryBuilder->handle($intake),
            'placementTypes' => AircoPlacementType::cases(),
            'configurationTypes' => AircoConfigurationType::cases(),
            'connectionTypes' => AircoConnectionType::cases(),
            'connectionStatuses' => AircoConnectionStatus::cases(),
            'followUpTypes' => FollowUpItemType::cases(),
            'siteVisitReasons' => InstallationSiteVisitReason::cases(),
            'proposalDeltas' => InstallationProposalDelta::cases(),
        ]);
    }

    public function storeRoom(
        Request $request,
        Intake $intake,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'use_type' => ['nullable', 'in:bedroom,living_room,office,attic,other'],
            'length_m' => ['nullable', 'numeric', 'between:0.5,100'],
            'width_m' => ['nullable', 'numeric', 'between:0.5,100'],
            'height_m' => ['nullable', 'numeric', 'between:1.5,10'],
        ]);
        $aircoSurvey->createRoom($intake, $this->user($request), $data);

        return $this->back($intake, 'Ruimte toegevoegd.');
    }

    public function storePlacement(
        Request $request,
        Intake $intake,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $data = $request->validate([
            'airco_room_id' => [
                'nullable',
                'integer',
                Rule::exists('airco_rooms', 'id')->where('intake_id', $intake->id),
            ],
            'type' => ['required', Rule::enum(AircoPlacementType::class)],
            'label' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1500'],
            'status' => ['nullable', Rule::enum(AircoOptionStatus::class)],
        ]);
        $aircoSurvey->createPlacement($intake, $this->user($request), $data);

        return $this->back($intake, 'Plaatsingsoptie toegevoegd.');
    }

    public function storeInstallationOption(
        Request $request,
        Intake $intake,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:160'],
            'configuration_type' => ['required', Rule::enum(AircoConfigurationType::class)],
            'summary' => ['nullable', 'string', 'max:2000'],
            'cost_impact' => ['nullable', 'in:low,medium,high,unknown'],
            'placement_ids' => ['required', 'array', 'min:2'],
            'placement_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('airco_placement_options', 'id')->where('intake_id', $intake->id),
            ],
        ]);
        $aircoSurvey->createInstallationOption($intake, $this->user($request), $data);

        return $this->back($intake, 'Installatieoptie toegevoegd.');
    }

    public function selectInstallationOption(
        Request $request,
        Intake $intake,
        AircoInstallationOption $option,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $aircoSurvey->selectInstallationOption($intake, $this->user($request), $option);

        return $this->back($intake, 'Installatieoptie geselecteerd.');
    }

    public function storeConnection(
        Request $request,
        Intake $intake,
        AircoInstallationOption $option,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $data = $request->validate([
            'type' => ['required', Rule::enum(AircoConnectionType::class)],
            'label' => ['required', 'string', 'max:180'],
            'from_placement_id' => [
                'nullable',
                'integer',
                Rule::exists('airco_placement_options', 'id')->where('intake_id', $intake->id),
            ],
            'to_placement_id' => [
                'nullable',
                'integer',
                Rule::exists('airco_placement_options', 'id')->where('intake_id', $intake->id),
            ],
            'status' => ['required', Rule::enum(AircoConnectionStatus::class)],
            'length_class' => ['nullable', 'in:short,medium,long,unknown'],
            'segments_text' => ['nullable', 'string', 'max:4000'],
            'obstacles_text' => ['nullable', 'string', 'max:3000'],
            'uncertainties_text' => ['nullable', 'string', 'max:3000'],
            'cost_impact' => ['nullable', 'in:low,medium,high,unknown'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
        ]);
        $data['segments'] = $this->lines($data['segments_text'] ?? null);
        $data['obstacles'] = $this->lines($data['obstacles_text'] ?? null);
        $data['uncertainties'] = $this->lines($data['uncertainties_text'] ?? null);
        $aircoSurvey->createConnection($intake, $this->user($request), $option, $data);

        return $this->back($intake, 'Technische verbinding toegevoegd.');
    }

    public function storeNote(
        Request $request,
        Intake $intake,
        DossierSubject $subject,
        SaveInstallerObservation $saveObservation,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $this->guardWorkspaceSubject($intake, $subject);
        $data = $request->validate([
            'text' => ['required', 'string', 'max:3000'],
        ]);
        $saveObservation->handle(
            $intake,
            $this->user($request),
            $subject,
            'installer_note.'.Str::lower(Str::ulid()->toBase32()),
            $data['text'],
            'installer_note',
        );

        return $this->back($intake, 'Technische notitie toegevoegd.');
    }

    public function confirmObservation(
        Request $request,
        Intake $intake,
        DossierRecord $record,
        ConfirmInstallerObservation $confirmObservation,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($record->intake_id === $intake->id, 404);
        $data = $request->validate([
            'text' => ['sometimes', 'required', 'string', 'max:3000'],
        ]);
        $adjustedText = array_key_exists('text', $data) ? $data['text'] : null;
        $confirmObservation->handle(
            $intake,
            $this->user($request),
            $record,
            $adjustedText,
        );

        return $this->back(
            $intake,
            $adjustedText === null
                ? 'Technische constatering bevestigd.'
                : 'Technische constatering aangepast en bevestigd.',
        );
    }

    public function storeEvidence(
        Request $request,
        Intake $intake,
        DossierSubject $subject,
        StoreInstallerDossierUpload $storeUpload,
        StartPipeRouteSession $startRoute,
        AddPipeRoutePhoto $addRoutePhoto,
        SuggestInstallerPhotoObservations $suggestPhotoObservations,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $this->guardWorkspaceSubject($intake, $subject);
        $data = $request->validate([
            'photo' => ['required', 'file', 'max:'.config('intake.uploads.max_kilobytes', 5120)],
            'route_segment_label' => ['nullable', 'string', 'max:160'],
        ]);
        $connection = $subject->type === 'airco_connection'
            ? AircoConnection::query()
                ->where('intake_id', $intake->id)
                ->where('dossier_subject_id', $subject->id)
                ->firstOrFail()
            : null;
        $photo = $request->file('photo');
        abort_unless($photo instanceof UploadedFile, 422);
        $upload = $storeUpload->handle(
            $intake,
            $this->user($request),
            $subject,
            $photo,
        );

        if ($connection instanceof AircoConnection) {
            $session = $startRoute->handle($intake, $connection);
            $addRoutePhoto->handle($session, $upload, $data['route_segment_label'] ?? null);
        } else {
            $run = $suggestPhotoObservations->handle($intake, $subject, $upload);
        }

        $hasSuggestion = isset($run) && DossierRecord::query()
            ->where('intake_id', $intake->id)
            ->where('source_type', 'ai')
            ->where('source_id', $run->id)
            ->where('status', DossierRecordStatus::Proposed)
            ->exists();

        return $this->back(
            $intake,
            $connection instanceof AircoConnection
                ? ($intake->is_demo
                    ? 'Foto opgeslagen als routesegment. Live AI-analyse staat uit in de demo.'
                    : 'Foto opgeslagen als routesegment.')
                : ($hasSuggestion
                    ? 'Foto opgeslagen. Controleer de voorgestelde technische constatering.'
                    : 'Foto opgeslagen bij '.$subject->label.'.'),
        );
    }

    public function requestContribution(
        Request $request,
        Intake $intake,
        CreateCustomerContributionRequest $createRequest,
        SendCustomerFollowUpRequest $sendRequest,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $rawItems = $request->input('contribution_items', []);
        $items = is_array($rawItems)
            ? array_values(array_filter($rawItems, static fn (mixed $item): bool => is_array($item) && filled($item['prompt'] ?? null)))
            : [];
        $validator = Validator::make(
            ['contribution_items' => $items],
            [
                'contribution_items' => ['required', 'array', 'min:1', 'max:'.config('intake.follow_up.max_items_per_round', 5)],
                'contribution_items.*.type' => ['required', Rule::enum(FollowUpItemType::class)],
                'contribution_items.*.prompt' => ['required', 'string', 'max:500'],
                'contribution_items.*.decision_area_key' => [
                    'nullable',
                    'in:request,capacity,placement,refrigerant,condensate,power,cost_risks,quote',
                ],
                'contribution_items.*.dossier_subject_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('dossier_subjects', 'id')->where('intake_id', $intake->id),
                ],
            ],
        );
        $validated = $validator->validate();
        $user = $this->user($request);
        $round = $createRequest->handle($intake, $user, $validated['contribution_items']);
        $mailResult = $sendRequest->handle($intake->fresh() ?? $intake, $round, $user);
        $message = match ($mailResult) {
            CustomerLinkMailResult::Sent => 'Gerichte klantopdracht aangemaakt en gemaild.',
            CustomerLinkMailResult::SkippedDemo => 'Klantweergave geactiveerd. In de demo wordt geen e-mail verstuurd.',
            CustomerLinkMailResult::SkippedLogMailer => 'Klantopdracht aangemaakt. Mail is lokaal uitgeschakeld; deel de link handmatig.',
            CustomerLinkMailResult::Failed => 'Klantopdracht aangemaakt, maar mailen mislukte. Deel de link handmatig.',
            default => 'Gerichte klantopdracht aangemaakt.',
        };

        return $this->back($intake, $message);
    }

    public function synthesizeRoute(
        Intake $intake,
        PipeRouteSession $session,
        SynthesizePipeRoute $synthesize,
        DecisionReadinessService $readiness,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($session->intake_id === $intake->id, 404);

        if ($intake->is_demo) {
            return $this->back($intake, 'Deze route is vooraf berekend; de demo gebruikt geen live AI.');
        }

        $synthesize->handle($session);
        $readiness->recalculate($intake);

        return $this->back($intake, 'Routesegmenten samengevat.');
    }

    public function approveRoute(
        Request $request,
        Intake $intake,
        PipeRouteSession $session,
        ApprovePipeRoute $approve,
        DecisionReadinessService $readiness,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($session->intake_id === $intake->id, 404);
        $approve->handle($session, $this->user($request), true);
        $readiness->recalculate($intake);

        return $this->back($intake, 'Route en gekoppelde verbinding goedgekeurd.');
    }

    public function synthesizeDossier(
        Intake $intake,
        SynthesizeSurveyDossier $synthesize,
    ): RedirectResponse {
        $this->authorize('update', $intake);

        if ($intake->is_demo) {
            return $this->back($intake, 'Het AI-voorstel is vooraf berekend; de demo gebruikt geen live AI.');
        }

        $run = $synthesize->handle($intake);

        if ($run === null) {
            return $this->back($intake, 'AI-dossiersynthese is in deze omgeving uitgeschakeld; de handmatige werkplek blijft volledig beschikbaar.');
        }

        return $this->back(
            $intake,
            $run->status->value === 'succeeded'
                ? 'AI-voorstel vernieuwd. Controleer de opties en uitzonderingen als geheel.'
                : 'AI-synthese kon niet worden afgerond; het bestaande dossier is ongewijzigd gebleven.',
        );
    }

    public function sendProposedTask(
        Request $request,
        Intake $intake,
        ContributionTask $task,
        CreateCustomerContributionRequest $createRequest,
        SendCustomerFollowUpRequest $sendRequest,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless(
            $task->intake_id === $intake->id
            && $task->status === ContributionTaskStatus::Proposed
            && ($task->meta['source_type'] ?? null) === 'ai',
            404,
        );
        $user = $this->user($request);
        $round = $createRequest->handle($intake, $user, [[
            'type' => $task->type,
            'prompt' => $task->prompt,
            'decision_area_key' => $task->decision_area_key,
            'dossier_subject_id' => $task->dossier_subject_id,
        ]]);
        $task->update(['status' => ContributionTaskStatus::Cancelled]);
        $mailResult = $sendRequest->handle($intake->fresh() ?? $intake, $round, $user);

        return $this->back($intake, match ($mailResult) {
            CustomerLinkMailResult::Sent => 'AI-taak gecontroleerd en als gerichte klantopdracht gemaild.',
            CustomerLinkMailResult::SkippedDemo => 'Klantweergave geactiveerd. In de demo wordt geen e-mail verstuurd.',
            CustomerLinkMailResult::SkippedLogMailer => 'Klantopdracht aangemaakt. Mail is lokaal uitgeschakeld; deel de link handmatig.',
            CustomerLinkMailResult::Failed => 'Klantopdracht aangemaakt, maar mailen mislukte. Deel de link handmatig.',
            default => 'AI-taak gecontroleerd en als gerichte klantopdracht aangemaakt.',
        });
    }

    public function complete(
        Request $request,
        Intake $intake,
        CompleteInstallerSurvey $complete,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $complete->handle($intake, $this->user($request));

        return $this->back($intake, 'Uw opnamebijdrage is afgerond; het beslisdossier is opnieuw beoordeeld.');
    }

    public function recordOutcome(
        Request $request,
        Intake $intake,
        RecordInstallationOutcome $recordOutcome,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $data = $request->validate([
            'result' => ['required', 'in:remote_quote,estimate,site_visit,rejected,installed'],
            'active_installer_minutes' => ['nullable', 'integer', 'between:0,10000'],
            'customer_minutes' => ['nullable', 'integer', 'between:0,10000'],
            'site_visit_occurred' => ['sometimes', 'boolean'],
            'site_visit_reasons' => ['nullable', 'array', 'max:3'],
            'site_visit_reasons.*' => ['required', 'distinct', Rule::enum(InstallationSiteVisitReason::class)],
            'quote_type' => ['nullable', 'in:remote,estimate,after_site_visit'],
            'installation_surprise' => ['nullable', 'in:none,minor,major'],
            'surprise_notes' => ['nullable', 'string', 'max:3000'],
            'selected_installation_option_id' => [
                'nullable',
                'integer',
                Rule::exists('airco_installation_options', 'id')->where('intake_id', $intake->id),
            ],
            'proposal_assessed' => ['sometimes', 'boolean'],
            'proposal_delta_codes' => ['nullable', 'array', 'max:7'],
            'proposal_delta_codes.*' => ['required', 'distinct', Rule::enum(InstallationProposalDelta::class)],
            'installed_at' => ['nullable', 'date'],
        ]);
        $data['site_visit_occurred'] = $request->boolean('site_visit_occurred');
        $data['proposal_assessed'] = $request->boolean('proposal_assessed');
        $recordOutcome->handle($intake, $this->user($request), $data);

        return $this->back($intake, 'Uitkomst opgeslagen. De tijd- en ritbesparing telt nu mee in Resultaten.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function guardWorkspaceSubject(Intake $intake, DossierSubject $subject): void
    {
        $belongsToObject = match ($subject->type) {
            'airco_room' => AircoRoom::query()
                ->where('intake_id', $intake->id)
                ->where('dossier_subject_id', $subject->id)
                ->exists(),
            'airco_placement' => AircoPlacementOption::query()
                ->where('intake_id', $intake->id)
                ->where('dossier_subject_id', $subject->id)
                ->exists(),
            'airco_connection' => AircoConnection::query()
                ->where('intake_id', $intake->id)
                ->where('dossier_subject_id', $subject->id)
                ->exists(),
            default => false,
        };

        abort_unless(
            $belongsToObject
            && $subject->intake_id === $intake->id
            && $subject->company_id === $intake->company_id,
            404,
        );
    }

    /** @return list<string> */
    private function lines(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return collect(preg_split('/\R+/', $value) ?: [])
            ->map(static fn (string $line): string => trim($line))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function back(Intake $intake, string $status): RedirectResponse
    {
        return redirect()
            ->route('intakes.workspace', $intake)
            ->with('status', $status);
    }
}
