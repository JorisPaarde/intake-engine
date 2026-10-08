<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Actions\SuggestInstallerPhotoObservations;
use App\Domains\AI\Actions\SynthesizePipeRoute;
use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Support\DossierSynthesisRefreshPresenter;
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
use App\Domains\Intake\Models\InstallationOutcome;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\DossierOverviewBuilder;
use App\Domains\Intake\Services\ExternalFactPresenter;
use App\Domains\Intake\Services\InstallationOptionPreferenceService;
use App\Domains\Intake\Services\InstallerPhotoGalleryBuilder;
use App\Domains\Intake\Support\CustomerFacingTaskText;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
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
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SurveyWorkspaceController extends Controller
{
    public function show(
        Request $request,
        Intake $intake,
        DossierManager $dossierManager,
        DossierOverviewBuilder $overviewBuilder,
        ExternalFactPresenter $externalFactPresenter,
        InstallerPhotoGalleryBuilder $photoGalleryBuilder,
        InstallationOptionPreferenceService $preferenceService,
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

        $demoScenarioLoaded = $intake->is_demo
            && $intake->activityEvents()
                ->where('event', 'demo_scenario_loaded')
                ->exists();

        $customerTaskDrafts = $this->sessionCustomerTaskDrafts($request);
        $customerTaskDraft = $customerTaskDrafts[0] ?? null;

        return view('installer.intakes.workspace', [
            'intake' => $intake,
            'dossier' => $overviewBuilder->build($intake),
            'externalData' => $externalFactPresenter->present($intake),
            'photoGroups' => $photoGalleryBuilder->handle($intake),
            'placementTypes' => AircoPlacementType::cases(),
            'configurationTypes' => AircoConfigurationType::cases(),
            'connectionTypes' => AircoConnectionType::cases(),
            'connectionStatuses' => AircoConnectionStatus::cases(),
            'followUpTypes' => $this->manualFollowUpTypes(),
            'siteVisitReasons' => InstallationSiteVisitReason::cases(),
            'proposalDeltas' => InstallationProposalDelta::cases(),
            'demoScenarioLoaded' => $demoScenarioLoaded,
            'customerTaskDraft' => $customerTaskDraft,
            'customerTaskDrafts' => $customerTaskDrafts,
            'preferenceState' => $preferenceService->workspaceState($intake),
        ]);
    }

    public function prepareContribution(Request $request, Intake $intake): RedirectResponse
    {
        $this->authorize('update', $intake);
        $data = $request->validate([
            'type' => ['required', Rule::enum(FollowUpItemType::class)],
            'prompt' => ['required', 'string', 'max:500'],
            'decision_area_key' => [
                'nullable',
                'in:request,capacity,placement,refrigerant,condensate,power,cost_risks,quote',
            ],
            'dossier_subject_id' => [
                'nullable',
                'integer',
                Rule::exists('dossier_subjects', 'id')->where('intake_id', $intake->id),
            ],
        ], [
            'type.required' => 'Kies een type opdracht (tekst, foto of document).',
            'prompt.required' => 'Schrijf wat de klant moet doen.',
            'prompt.max' => 'Houd de opdracht kort (maximaal 500 tekens).',
            'decision_area_key.in' => 'Kies een geldig onderdeel van de opname.',
            'dossier_subject_id.exists' => 'Die koppeling hoort niet bij deze opname.',
        ], [
            'type' => 'type opdracht',
            'prompt' => 'opdrachttekst',
        ]);

        $draft = [
            'type' => $data['type'] instanceof FollowUpItemType
                ? $data['type']->value
                : (string) $data['type'],
            'prompt' => CustomerFacingTaskText::ensureCustomerFacing(trim((string) $data['prompt'])),
            'decision_area_key' => $data['decision_area_key'] ?? null,
            'dossier_subject_id' => isset($data['dossier_subject_id'])
                ? (int) $data['dossier_subject_id']
                : null,
        ];

        if ($draft['prompt'] === '') {
            return redirect()
                ->to(route('intakes.workspace', $intake).'#demo-customer-task')
                ->withErrors([
                    'contribution_items' => 'Schrijf een begrijpelijke opdracht voor de klant (geen interne installateurstekst).',
                ]);
        }

        $maxItems = (int) config('intake.follow_up.max_items_per_round', 5);
        $drafts = $this->sessionCustomerTaskDrafts($request);
        $fingerprint = $this->customerTaskDraftFingerprint($draft);
        $replaced = false;

        foreach ($drafts as $index => $existing) {
            if ($this->customerTaskDraftFingerprint($existing) === $fingerprint) {
                $drafts[$index] = $draft;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            if (count($drafts) >= $maxItems) {
                return redirect()
                    ->to(route('intakes.workspace', $intake).'#demo-customer-task')
                    ->withErrors([
                        'contribution_items' => "Je conceptlijst heeft al {$maxItems} taken. Verstuur of pas ze aan vóór je er meer toevoegt.",
                    ])
                    ->with('customer_task_drafts', $drafts);
            }

            $drafts[] = $draft;
        }

        $request->session()->put('customer_task_drafts', $drafts);
        $request->session()->forget('customer_task_draft');

        $count = count($drafts);
        $status = $replaced
            ? 'Concepttaak bijgewerkt. Controleer de klanttekst en verstuur daarna de ronde.'
            : ($count === 1
                ? 'Concepttaak toegevoegd. Controleer de klanttekst. Voeg desgewenst meer taken toe en verstuur daarna één ronde.'
                : "Concepttaak toegevoegd ({$count} in deze ronde). Controleer de klantteksten en verstuur daarna.");

        return redirect()
            ->to(route('intakes.workspace', $intake).'#demo-customer-task')
            ->with('status', $status);
    }

    /** @return list<FollowUpItemType> */
    private function manualFollowUpTypes(): array
    {
        $types = [];
        foreach (FollowUpItemType::cases() as $type) {
            if ($type === FollowUpItemType::Choice) {
                continue;
            }
            $types[] = $type;
        }

        return $types;
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
            'area_m2' => ['nullable', 'numeric', 'between:1,500'],
        ]);
        $aircoSurvey->createRoom($intake, $this->user($request), $data);

        return $this->back($intake, 'Ruimte toegevoegd.');
    }

    public function updateRoom(
        Request $request,
        Intake $intake,
        AircoRoom $room,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($room->intake_id === $intake->id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'use_type' => ['nullable', 'in:bedroom,living_room,office,attic,other'],
            'length_m' => ['nullable', 'numeric', 'between:0.5,100'],
            'width_m' => ['nullable', 'numeric', 'between:0.5,100'],
            'height_m' => ['nullable', 'numeric', 'between:1.5,10'],
            'area_m2' => ['nullable', 'numeric', 'between:1,500'],
        ]);
        $aircoSurvey->updateRoom($intake, $this->user($request), $room, $data);

        return $this->back($intake, 'Ruimte bijgewerkt.', 'room-'.$room->id);
    }

    public function syncRoomUnitCoupling(
        Request $request,
        Intake $intake,
        AircoRoom $room,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($room->intake_id === $intake->id, 404);
        $data = $request->validate([
            'indoor_label' => ['required', 'string', 'max:160'],
            'outdoor_placement_id' => [
                'nullable',
                'integer',
                Rule::exists('airco_placement_options', 'id')
                    ->where('intake_id', $intake->id)
                    ->where('type', AircoPlacementType::OutdoorUnit->value),
            ],
            'outdoor_label' => ['nullable', 'string', 'max:160'],
            'configuration_type' => ['required', Rule::enum(AircoConfigurationType::class)],
            'installation_option_id' => [
                'nullable',
                'integer',
                Rule::exists('airco_installation_options', 'id')->where('intake_id', $intake->id),
            ],
        ]);
        $aircoSurvey->syncRoomUnitCoupling($intake, $this->user($request), $room, $data);

        return $this->back($intake, 'Binnen- en buitenunit gekoppeld.');
    }

    public function storePlacement(
        Request $request,
        Intake $intake,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $data = $request->validate([
            'airco_room_id' => [
                Rule::requiredIf(fn (): bool => (string) $request->input('type') === AircoPlacementType::IndoorUnit->value),
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

        return $this->back($intake, 'Unit toegevoegd.');
    }

    public function updatePlacement(
        Request $request,
        Intake $intake,
        AircoPlacementOption $placement,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($placement->intake_id === $intake->id, 404);
        $data = $request->validate([
            'airco_room_id' => [
                Rule::requiredIf(fn (): bool => (string) $request->input('type') === AircoPlacementType::IndoorUnit->value),
                'nullable',
                'integer',
                Rule::exists('airco_rooms', 'id')->where('intake_id', $intake->id),
            ],
            'type' => ['required', Rule::enum(AircoPlacementType::class)],
            'label' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1500'],
        ]);
        $aircoSurvey->updatePlacement($intake, $this->user($request), $placement, $data);

        return $this->back($intake, 'Unit bijgewerkt.', 'placement-'.$placement->id);
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

        return $this->back($intake, 'Keuze toegevoegd.');
    }

    public function selectInstallationOption(
        Request $request,
        Intake $intake,
        AircoInstallationOption $option,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($option->intake_id === $intake->id, 404);
        $aircoSurvey->selectInstallationOption($intake, $this->user($request), $option);

        return $this->back($intake, 'Keuze geselecteerd.');
    }

    public function markInstallationOptionFeasible(
        Request $request,
        Intake $intake,
        AircoInstallationOption $option,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($option->intake_id === $intake->id, 404);
        $aircoSurvey->markInstallationOptionFeasible($intake, $this->user($request), $option);

        return $this->back($intake, 'Keuze gemarkeerd als haalbaar.');
    }

    public function markInstallationOptionInfeasible(
        Request $request,
        Intake $intake,
        AircoInstallationOption $option,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($option->intake_id === $intake->id, 404);
        $data = $request->validate([
            'infeasibility_reason' => ['required', 'string', 'max:1000'],
        ], [
            'infeasibility_reason.required' => 'Schrijf kort waarom deze keuze niet haalbaar is.',
        ]);
        $aircoSurvey->markInstallationOptionInfeasible(
            $intake,
            $this->user($request),
            $option,
            $data['infeasibility_reason'],
        );

        return $this->back($intake, 'Keuze gemarkeerd als niet haalbaar.');
    }

    public function requestInstallationPreference(
        Request $request,
        Intake $intake,
        InstallationOptionPreferenceService $preferenceService,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $result = $preferenceService->requestPreference($intake, $this->user($request));

        return $this->back($intake, $this->contributionMailMessage($result['mail']));
    }

    public function updateConfigurationType(
        Request $request,
        Intake $intake,
        AircoInstallationOption $option,
        AircoSurveyService $aircoSurvey,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($option->intake_id === $intake->id, 404);
        $data = $request->validate([
            'configuration_type' => ['required', Rule::enum(AircoConfigurationType::class)],
        ]);
        $aircoSurvey->updateConfigurationType(
            $intake,
            $this->user($request),
            $option,
            $data['configuration_type'],
        );

        return $this->back($intake, 'Configuratie bijgewerkt.');
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
        DeriveIntentFromRequest $deriveIntentFromRequest,
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

        $deriveIntentFromRequest->handle($intake->fresh() ?? $intake);

        return $this->back($intake, 'Notitie toegevoegd.');
    }

    public function confirmObservation(
        Request $request,
        Intake $intake,
        DossierRecord $record,
        ConfirmInstallerObservation $confirmObservation,
        DeriveIntentFromRequest $deriveIntentFromRequest,
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

        $deriveIntentFromRequest->handle($intake->fresh() ?? $intake);

        return $this->back(
            $intake,
            $adjustedText === null
                ? 'Notitie bevestigd.'
                : 'Notitie aangepast en bevestigd.',
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
            'photo' => ['required', 'file', 'max:'.config('intake.uploads.max_kilobytes', 8192)],
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
                ? 'Foto opgeslagen als routesegment.'
                : ($hasSuggestion
                    ? 'Foto opgeslagen. Controleer wat de AI voorstelt.'
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
        $maxItems = (int) config('intake.follow_up.max_items_per_round', 5);
        $validator = Validator::make(
            ['contribution_items' => $items],
            [
                'contribution_items' => ['required', 'array', 'min:1', 'max:'.$maxItems],
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
            [
                'contribution_items.required' => 'Vul minstens één klantopdracht in. Schrijf wat de klant moet doen.',
                'contribution_items.min' => 'Vul minstens één klantopdracht in. Schrijf wat de klant moet doen.',
                'contribution_items.max' => "Voeg maximaal {$maxItems} klantopdrachten per keer toe.",
                'contribution_items.*.type.required' => 'Kies bij elke opdracht een type (tekst, foto of document).',
                'contribution_items.*.prompt.required' => 'Schrijf bij elke opdracht wat de klant moet doen.',
                'contribution_items.*.prompt.max' => 'Houd elke opdracht kort (maximaal 500 tekens).',
                'contribution_items.*.decision_area_key.in' => 'Kies een geldig onderdeel van de opname, of laat Algemene opname staan.',
                'contribution_items.*.dossier_subject_id.exists' => 'Die koppeling hoort niet bij deze opname.',
            ],
            [
                'contribution_items' => 'klantopdrachten',
                'contribution_items.*.type' => 'type opdracht',
                'contribution_items.*.prompt' => 'opdrachttekst',
            ],
        );
        $validated = $validator->validate();
        $items = array_map(static function (array $item): array {
            $item['prompt'] = CustomerFacingTaskText::ensureCustomerFacing(trim((string) $item['prompt']));

            return $item;
        }, $validated['contribution_items']);
        $items = array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['prompt'] !== '',
        ));

        if ($items === []) {
            throw ValidationException::withMessages([
                'contribution_items' => 'Schrijf een begrijpelijke opdracht voor de klant (geen interne installateurstekst).',
            ]);
        }

        $user = $this->user($request);
        $round = $createRequest->handle($intake, $user, $items);
        $mailResult = $sendRequest->handle($intake->fresh() ?? $intake, $round, $user);
        $request->session()->forget(['customer_task_drafts', 'customer_task_draft']);

        return $this->back($intake, $this->contributionMailMessage($mailResult));
    }

    public function requestQuickContribution(
        Request $request,
        Intake $intake,
        CreateCustomerContributionRequest $createRequest,
        SendCustomerFollowUpRequest $sendRequest,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $data = $request->validate([
            'type' => ['required', Rule::enum(FollowUpItemType::class)],
            'prompt' => ['required', 'string', 'max:500'],
            'decision_area_key' => [
                'nullable',
                'in:request,capacity,placement,refrigerant,condensate,power,cost_risks,quote',
            ],
            'dossier_subject_id' => [
                'nullable',
                'integer',
                Rule::exists('dossier_subjects', 'id')->where('intake_id', $intake->id),
            ],
        ], [
            'type.required' => 'Kies een type opdracht (tekst, foto of document).',
            'prompt.required' => 'Schrijf wat de klant moet doen.',
            'prompt.max' => 'Houd de opdracht kort (maximaal 500 tekens).',
            'decision_area_key.in' => 'Kies een geldig onderdeel van de opname.',
            'dossier_subject_id.exists' => 'Die koppeling hoort niet bij deze opname.',
        ], [
            'type' => 'type opdracht',
            'prompt' => 'opdrachttekst',
        ]);
        $prompt = CustomerFacingTaskText::ensureCustomerFacing(trim((string) $data['prompt']));

        if ($prompt === '') {
            throw ValidationException::withMessages([
                'prompt' => 'Schrijf een begrijpelijke opdracht voor de klant (geen interne installateurstekst).',
            ]);
        }

        $user = $this->user($request);
        $round = $createRequest->handle($intake, $user, [[
            'type' => $data['type'],
            'prompt' => $prompt,
            'decision_area_key' => $data['decision_area_key'] ?? null,
            'dossier_subject_id' => $data['dossier_subject_id'] ?? null,
        ]]);
        $mailResult = $sendRequest->handle($intake->fresh() ?? $intake, $round, $user);
        $request->session()->forget(['customer_task_drafts', 'customer_task_draft']);

        return $this->back($intake, $this->contributionMailMessage($mailResult));
    }

    public function synthesizeRoute(
        Intake $intake,
        PipeRouteSession $session,
        SynthesizePipeRoute $synthesize,
        DecisionReadinessService $readiness,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($session->intake_id === $intake->id, 404);

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
        DossierSynthesisRefreshPresenter $refreshPresenter,
    ): RedirectResponse {
        $this->authorize('update', $intake);

        $run = $synthesize->handle($intake);

        if ($run === null) {
            return redirect()
                ->route('intakes.workspace', $intake)
                ->with('error', 'AI-dossiersynthese is in deze omgeving uitgeschakeld; de handmatige werkplek blijft volledig beschikbaar.')
                ->with('ai_synthesis_retry', true);
        }

        if ($run->status === AiRunStatus::Succeeded) {
            return $this->back($intake, 'AI-voorstel vernieuwd. Controleer de keuzes en uitzonderingen als geheel.');
        }

        if ($run->status === AiRunStatus::Partial) {
            $flash = $refreshPresenter->partialFlash($run);

            return redirect()
                ->route('intakes.workspace', $intake)
                ->with('status', $flash['message'])
                ->with('ai_synthesis_partial', true)
                ->with('ai_synthesis_partial_detail', $flash['technical_detail']);
        }

        $detail = is_string($run->error_message) && trim($run->error_message) !== ''
            ? trim($run->error_message)
            : 'De AI-aanbieder gaf geen bruikbaar voorstel terug.';

        // Keep failure flashes free of internal section keys when possible.
        $safeDetail = preg_match('/\b[a-z]+_[a-z0-9_.]+\b/i', $detail) === 1
            ? 'De AI-aanbieder gaf geen bruikbaar voorstel terug.'
            : $detail;

        return redirect()
            ->route('intakes.workspace', $intake)
            ->with(
                'error',
                'AI-synthese kon niet worden afgerond: '.$safeDetail.' Het bestaande dossier is ongewijzigd gebleven.',
            )
            ->with('ai_synthesis_retry', true)
            ->with('ai_synthesis_error', $detail);
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
            'prompt' => CustomerFacingTaskText::ensureCustomerFacing((string) $task->prompt),
            'decision_area_key' => $task->decision_area_key,
            'dossier_subject_id' => $task->dossier_subject_id,
        ]]);
        $task->update(['status' => ContributionTaskStatus::Cancelled]);
        $mailResult = $sendRequest->handle($intake->fresh() ?? $intake, $round, $user);

        return $this->back($intake, match ($mailResult) {
            CustomerLinkMailResult::Sent => 'AI-taak gecontroleerd en als klanttaak gemaild.',
            CustomerLinkMailResult::SkippedDemo => 'Klantweergave geactiveerd. In de demo sturen we geen e-mail.',
            CustomerLinkMailResult::SkippedLogMailer => 'Klanttaak aangemaakt. Mail is lokaal uit. Deel de link zelf.',
            CustomerLinkMailResult::Failed => 'Klanttaak aangemaakt, maar mailen mislukte. Deel de link zelf.',
            default => 'AI-taak gecontroleerd en als klanttaak aangemaakt.',
        });
    }

    private function contributionMailMessage(CustomerLinkMailResult $mailResult): string
    {
        return match ($mailResult) {
            CustomerLinkMailResult::Sent => 'Klanttaak aangemaakt en gemaild.',
            CustomerLinkMailResult::SkippedDemo => 'Klantweergave geactiveerd. In de demo sturen we geen e-mail.',
            CustomerLinkMailResult::SkippedLogMailer => 'Klanttaak aangemaakt. Mail is lokaal uit. Deel de link zelf.',
            CustomerLinkMailResult::Failed => 'Klanttaak aangemaakt, maar mailen mislukte. Deel de link zelf.',
            default => 'Klanttaak aangemaakt.',
        };
    }

    public function complete(
        Request $request,
        Intake $intake,
        CompleteInstallerSurvey $complete,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        $complete->handle($intake, $this->user($request));

        return $this->back($intake, 'Je opnamebijdrage is afgerond; het beslisdossier is opnieuw beoordeeld.');
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
        $outcome = $recordOutcome->handle($intake, $this->user($request), $data);

        return $this->back($intake, $this->outcomeStatusMessage($outcome));
    }

    private function outcomeStatusMessage(InstallationOutcome $outcome): string
    {
        $hasInstallerMinutes = $outcome->active_installer_minutes !== null;
        $hasCustomerMinutes = $outcome->customer_minutes !== null;
        $hasMinutes = $hasInstallerMinutes || $hasCustomerMinutes;

        $resultLabel = match ($outcome->result) {
            'remote_quote' => 'op afstand geoffreerd',
            'estimate' => 'prijsindicatie',
            'site_visit' => 'locatiebezoek nodig',
            'installed' => 'geplaatst',
            'rejected' => 'afgewezen',
            default => 'uitkomst',
        };

        if ($outcome->result === 'site_visit' && ! $outcome->site_visit_occurred) {
            $base = 'Uitkomst opgeslagen: locatiebezoek nodig (nog niet gemarkeerd als uitgevoerd).';
        } elseif ($outcome->site_visit_occurred) {
            $base = 'Uitkomst opgeslagen: locatiebezoek uitgevoerd ('.$resultLabel.').';
        } else {
            $base = 'Uitkomst opgeslagen: '.$resultLabel.'.';
        }

        if ($hasMinutes) {
            return $base.' De tijd- en ritbesparing telt nu mee in Resultaten.';
        }

        return $base.' Minuten kun je later invullen; zonder tijden telt de besparing nog niet mee in Resultaten.';
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

    /**
     * @param  string|null  $fragment  Anchor of the edited block, so the installer lands back on it
     *                                 instead of at the top of the page (UX-pakket #15).
     */
    private function back(Intake $intake, string $status, ?string $fragment = null): RedirectResponse
    {
        $redirect = redirect()
            ->route('intakes.workspace', $intake)
            ->with('status', $status);

        return $fragment !== null ? $redirect->withFragment($fragment) : $redirect;
    }

    /**
     * @return list<array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string|null,
     *     dossier_subject_id: int|null
     * }>
     */
    private function sessionCustomerTaskDrafts(Request $request): array
    {
        $raw = $request->session()->get('customer_task_drafts');

        if (! is_array($raw) || $raw === []) {
            $legacy = $request->session()->get('customer_task_draft');
            if (is_array($legacy)
                && is_string($legacy['type'] ?? null)
                && is_string($legacy['prompt'] ?? null)
                && trim((string) $legacy['prompt']) !== '') {
                $raw = [$legacy];
            } else {
                return [];
            }
        }

        $maxItems = (int) config('intake.follow_up.max_items_per_round', 5);
        $drafts = [];

        foreach ($raw as $item) {
            if (! is_array($item)
                || ! is_string($item['type'] ?? null)
                || ! is_string($item['prompt'] ?? null)) {
                continue;
            }

            $prompt = CustomerFacingTaskText::ensureCustomerFacing(trim((string) $item['prompt']));

            if ($prompt === '') {
                continue;
            }

            $drafts[] = [
                'type' => (string) $item['type'],
                'prompt' => $prompt,
                'decision_area_key' => isset($item['decision_area_key']) && is_string($item['decision_area_key'])
                    ? $item['decision_area_key']
                    : null,
                'dossier_subject_id' => isset($item['dossier_subject_id']) && is_numeric($item['dossier_subject_id'])
                    ? (int) $item['dossier_subject_id']
                    : null,
            ];

            if (count($drafts) >= $maxItems) {
                break;
            }
        }

        return $drafts;
    }

    /**
     * @param  array{
     *     type?: mixed,
     *     prompt?: mixed,
     *     decision_area_key?: mixed,
     *     dossier_subject_id?: mixed
     * }  $draft
     */
    private function customerTaskDraftFingerprint(array $draft): string
    {
        return implode('|', [
            (string) ($draft['type'] ?? ''),
            (string) ($draft['decision_area_key'] ?? ''),
            (string) ($draft['dossier_subject_id'] ?? ''),
        ]);
    }
}
