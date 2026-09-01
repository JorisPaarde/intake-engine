<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\StoreInstallerDossierUpload;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\ContributionAudience;
use App\Enums\ContributionTaskStatus;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Enums\FollowUpItemType;
use App\Enums\PipeRouteStatus;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class DemoSurveyScenarioBuilder
{
    public function __construct(
        private readonly AircoSurveyService $aircoSurvey,
        private readonly DossierManager $dossierManager,
        private readonly DecisionReadinessService $decisionReadiness,
        private readonly StoreInstallerDossierUpload $storeUpload,
    ) {}

    public function build(Intake $intake, User $installer): void
    {
        if (! $intake->is_demo || (int) $intake->company_id !== (int) $installer->company_id) {
            throw new \InvalidArgumentException('Het demoscenario hoort bij een geïsoleerde demo-opname.');
        }

        $this->storeExampleContext($intake);
        $this->dossierManager->initialize($intake);

        // initialize() syncs rooms from derived answers; the sample dossier replaces that set.
        $intake->aircoInstallationOptions()->delete();
        $intake->aircoRooms()->delete();

        $run = $this->createPrecomputedAiRun($intake);
        $parents = $this->aircoSurvey->createRoom($intake, $installer, [
            'name' => 'Slaapkamer ouders',
            'use_type' => 'bedroom',
            'length_m' => 4.2,
            'width_m' => 3.5,
            'height_m' => 2.6,
        ]);
        $office = $this->aircoSurvey->createRoom($intake, $installer, [
            'name' => 'Werkkamer',
            'use_type' => 'office',
            'length_m' => 3.8,
            'width_m' => 3.1,
            'height_m' => 2.6,
        ]);
        $parents->update(['source_type' => 'customer', 'source_id' => null]);
        $office->update(['source_type' => 'customer', 'source_id' => null]);

        $insideParents = $this->aiPlacement($intake, $installer, $run, [
            'airco_room_id' => $parents->id,
            'type' => AircoPlacementType::IndoorUnit,
            'label' => 'Boven de slaapkamerdeur',
            'description' => 'Vrije hoge wand; korte doorvoer richting achtergevel is op de overzichtsfoto aannemelijk.',
            'confidence' => 0.92,
        ]);
        $insideOffice = $this->aiPlacement($intake, $installer, $run, [
            'airco_room_id' => $office->id,
            'type' => AircoPlacementType::IndoorUnit,
            'label' => 'Hoge wand naast de deur',
            'description' => 'Vrije uitblaasrichting en een aansluitende route naar dezelfde achtergevel.',
            'confidence' => 0.89,
        ]);
        $outside = $this->aiPlacement($intake, $installer, $run, [
            'type' => AircoPlacementType::OutdoorUnit,
            'label' => 'Plat dak van de achteraanbouw',
            'description' => 'Bereikbaar vanuit de tuin en bruikbaar voor één compacte multi-splitbuitenunit.',
            'confidence' => 0.88,
        ]);
        $power = $this->aiPlacement($intake, $installer, $run, [
            'type' => AircoPlacementType::PowerSource,
            'label' => 'Nieuwe groep in de meterkast',
            'description' => 'De kast is volledig zichtbaar; één labelregel heeft op de voorbeeldfoto hinderlijke reflectie.',
            'confidence' => 0.76,
        ]);
        $drain = $this->aiPlacement($intake, $installer, $run, [
            'type' => AircoPlacementType::DrainPoint,
            'label' => 'Regenwaterafvoer achtergevel',
            'description' => 'De afvoer is op de gevel- en luchtfoto herkenbaar.',
            'confidence' => 0.91,
        ]);

        $option = $this->aircoSurvey->createInstallationOption($intake, $installer, [
            'label' => 'Keuze A · één multi-split',
            'configuration_type' => AircoConfigurationType::MultiSplit,
            'summary' => 'Eén buitenunit op de aanbouw bedient beide bovenruimtes en houdt de tuin vrij.',
            'cost_impact' => 'medium',
            'placement_ids' => [
                $insideParents->id,
                $insideOffice->id,
                $outside->id,
                $power->id,
                $drain->id,
            ],
        ]);
        $option->update([
            'source_type' => 'ai',
            'source_id' => $run->id,
            'confidence' => 0.88,
        ]);

        $refrigerantParents = $this->aiConnection($intake, $installer, $run, $option, [
            'type' => AircoConnectionType::Refrigerant,
            'label' => 'Koelleiding slaapkamer ouders',
            'from_placement_id' => $insideParents->id,
            'to_placement_id' => $outside->id,
            'status' => AircoConnectionStatus::Proposed,
            'length_class' => 'medium',
            'segments' => [
                'Doorvoer direct boven de slaapkamerdeur',
                'Via de overloop naar de achtergevel',
                'Langs de gevel omlaag naar het dak van de aanbouw',
            ],
            'cost_impact' => 'medium',
            'confidence' => 0.87,
        ]);
        $this->aiConnection($intake, $installer, $run, $option, [
            'type' => AircoConnectionType::Refrigerant,
            'label' => 'Koelleiding werkkamer',
            'from_placement_id' => $insideOffice->id,
            'to_placement_id' => $outside->id,
            'status' => AircoConnectionStatus::Plausible,
            'length_class' => 'medium',
            'segments' => [
                'Korte leiding boven het deurkozijn',
                'Bundelen met de route naar de achtergevel',
                'Aansluiten op de multi-splitbuitenunit',
            ],
            'cost_impact' => 'medium',
            'confidence' => 0.84,
        ]);
        $this->aiConnection($intake, $installer, $run, $option, [
            'type' => AircoConnectionType::Condensate,
            'label' => 'Condensafvoer slaapkamer ouders',
            'from_placement_id' => $insideParents->id,
            'to_placement_id' => $drain->id,
            'status' => AircoConnectionStatus::Plausible,
            'length_class' => 'medium',
            'segments' => ['Op afschot naar de achtergevel', 'Aansluiten bij de zichtbare hemelwaterafvoer'],
            'cost_impact' => 'low',
            'confidence' => 0.86,
        ]);
        $this->aiConnection($intake, $installer, $run, $option, [
            'type' => AircoConnectionType::Condensate,
            'label' => 'Condensafvoer werkkamer',
            'from_placement_id' => $insideOffice->id,
            'to_placement_id' => $drain->id,
            'status' => AircoConnectionStatus::Plausible,
            'length_class' => 'medium',
            'segments' => ['Op afschot boven de overloop', 'Gezamenlijk naar de achtergevel'],
            'cost_impact' => 'low',
            'confidence' => 0.83,
        ]);
        $this->aiConnection($intake, $installer, $run, $option, [
            'type' => AircoConnectionType::Power,
            'label' => 'Voeding naar buitenunit',
            'from_placement_id' => $power->id,
            'to_placement_id' => $outside->id,
            'status' => AircoConnectionStatus::Proposed,
            'length_class' => 'medium',
            'segments' => ['Nieuwe eindgroep', 'Kabelroute via kruipruimte of plint', 'Werkschakelaar bij buitenunit'],
            'uncertainties' => ['Controleer de deels onleesbare groepsaanduiding vóór de definitieve offerte.'],
            'cost_impact' => 'medium',
            'confidence' => 0.76,
        ]);

        $this->aircoSurvey->selectInstallationOption($intake, $installer, $option);

        $uploads = [
            'bedroom' => $this->storeExampleUpload($intake, $installer, $parents->subject, 'bedroom.jpg'),
            'office' => $this->storeExampleUpload($intake, $installer, $office->subject, 'home-office.jpg'),
            'facade' => $this->storeExampleUpload($intake, $installer, $outside->subject, 'rear-facade.jpg'),
            'fusebox' => $this->storeExampleUpload($intake, $installer, $power->subject, 'fusebox.jpg'),
        ];

        $this->storePrecomputedRoute($intake, $refrigerantParents, [
            ['upload' => $uploads['bedroom']->id, 'label' => 'Binnenpositie en eerste doorvoer'],
            ['upload' => $uploads['facade']->id, 'label' => 'Achtergevel en buitenunitpositie'],
        ]);
        $this->storeSynthesisRecord($intake, $run, array_values($uploads));
        $this->storeProposedCustomerTask($intake, $run, $power->subject, $uploads['fusebox']);
        $this->dossierManager->initialize($intake->fresh() ?? $intake);
        // Final initialize re-syncs answer-derived rooms; keep only the sample rooms.
        $intake->aircoRooms()
            ->where('source_type', 'template_bridge')
            ->delete();
        $this->decisionReadiness->recalculate($intake->fresh() ?? $intake);

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'system',
            'actor_id' => null,
            'event' => 'demo_scenario_prepared',
            'properties' => [
                'scenario' => 'installer-airco-v1',
                'synthetic_upload_count' => count($uploads),
                'external_effects_enabled' => false,
            ],
            'created_at' => now(),
        ]);
    }

    private function storeExampleContext(Intake $intake): void
    {
        $sourceGroups = [
            'BAG · fictief demo-voorbeeld' => [
                ['address_verification', 'Adrescontrole', ['status' => 'matched'], 'demo-address'],
                ['building_year', 'Bouwjaar', ['number' => 1996], 'demo-building'],
                ['floor_area_m2', 'Gebruiksoppervlakte', ['number' => 118, 'unit' => 'm²'], 'demo-address'],
                ['usage_purposes', 'Gebruiksdoel', ['values' => ['woonfunctie']], 'demo-address'],
            ],
            'EP-Online · fictief demo-voorbeeld' => [
                ['energy_label', 'Energielabel', ['value' => 'B', 'registered_at' => '2024-05-12'], 'demo-label'],
                ['energy_demand', 'Energiebehoefte', ['number' => 71.4, 'unit' => 'kWh/m²·jr'], 'demo-label'],
            ],
            '3DBAG · fictief demo-voorbeeld' => [
                ['building_height_m', 'Gebouwhoogte', ['number' => 8.7, 'unit' => 'm'], 'demo-geometry'],
                ['roof_type', 'Dakvorm', ['label' => 'Zadeldak'], 'demo-geometry'],
                ['floor_count', 'Verdiepingen', ['number' => 3], 'demo-geometry'],
            ],
        ];

        foreach ($sourceGroups as $source => $facts) {
            foreach ($facts as [$key, $label, $value, $reference]) {
                $intake->externalFacts()->updateOrCreate(
                    ['fact_key' => $key, 'source' => $source],
                    [
                        'label' => $label,
                        'value' => $value,
                        'source_reference' => $reference,
                        'source_url' => null,
                        'confidence' => 'high',
                        'captured_at' => now(),
                    ],
                );
            }
        }

        // Live PDOK aerial from the typed address must stay visible; sample load only
        // injects a synthetic aerial when no live capture exists (empty-address demos).
        if ($this->hasLivePdokAerial($intake)) {
            return;
        }

        $this->storeSyntheticAerial($intake);
    }

    private function hasLivePdokAerial(Intake $intake): bool
    {
        return $intake->externalFacts()
            ->where('fact_key', 'aerial_image')
            ->where('source', PdokAerialImageService::sourceName())
            ->exists();
    }

    private function storeSyntheticAerial(Intake $intake): void
    {
        $asset = resource_path('demo/evidence/aerial.jpg');
        $disk = (string) config('filesystems.media', 'local');
        $path = 'intakes/'.$intake->uuid.'/external/demo-aerial.jpg';

        if (! is_file($asset) || ! Storage::disk($disk)->put($path, File::get($asset))) {
            throw new RuntimeException('De synthetische demo-luchtfoto kon niet worden opgeslagen.');
        }

        try {
            $intake->externalFacts()->updateOrCreate(
                ['fact_key' => 'aerial_image', 'source' => 'PDOK Luchtfoto RGB · fictief demo-voorbeeld'],
                [
                    'label' => 'Luchtfoto rond voorbeeldwoning',
                    'value' => [
                        'media_disk' => $disk,
                        'media_path' => $path,
                        'mime_type' => 'image/jpeg',
                        'width' => 1536,
                        'height' => 1024,
                        'layer' => 'synthetic-demo',
                        'ground_width_meters' => 80,
                        'ground_height_meters' => 55,
                    ],
                    'source_reference' => 'synthetic-demo',
                    'source_url' => null,
                    'confidence' => 'high',
                    'captured_at' => now(),
                ],
            );
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    private function createPrecomputedAiRun(Intake $intake): AiRun
    {
        $output = [
            'summary' => 'Twee bovenruimtes lijken via één multi-split en een gezamenlijke achtergevelroute op afstand offerbaar. Alleen de groepsaanduiding in de meterkast verdient nog een gerichte controle.',
            'exceptions' => [[
                'label' => 'Groepsaanduiding deels onleesbaar',
                'decision_area_key' => 'power',
                'confidence' => 0.76,
            ]],
            'scenario' => 'installer-airco-v1',
        ];

        return AiRun::query()->create([
            'intake_id' => $intake->id,
            'type' => AiRunType::DossierSynthesis,
            'provider' => 'demo_precomputed',
            'model' => 'demo-scenario-v1',
            'prompt_version' => 'demo-scenario-v1',
            'input_hash' => hash('sha256', 'demo-scenario-v1:'.$intake->uuid),
            'output' => $output,
            'status' => AiRunStatus::Succeeded,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'image_count' => 4,
            'estimated_cost_cents' => 0,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  array{
     *     airco_room_id?: int,
     *     type: AircoPlacementType,
     *     label: string,
     *     description: string,
     *     confidence: float
     * }  $data
     */
    private function aiPlacement(
        Intake $intake,
        User $installer,
        AiRun $run,
        array $data,
    ): AircoPlacementOption {
        $placement = $this->aircoSurvey->createPlacement($intake, $installer, $data);
        $placement->update([
            'source_type' => 'ai',
            'source_id' => $run->id,
            'confidence' => $data['confidence'],
        ]);

        return $placement->fresh('subject') ?? $placement;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function aiConnection(
        Intake $intake,
        User $installer,
        AiRun $run,
        AircoInstallationOption $option,
        array $data,
    ): AircoConnection {
        $connection = $this->aircoSurvey->createConnection($intake, $installer, $option, $data);
        $connection->update([
            'source_type' => 'ai',
            'source_id' => $run->id,
            'confidence' => $data['confidence'],
        ]);

        return $connection->fresh('subject') ?? $connection;
    }

    private function storeExampleUpload(
        Intake $intake,
        User $installer,
        DossierSubject $subject,
        string $filename,
    ): IntakeUpload {
        $path = resource_path('demo/evidence/'.$filename);

        if (! is_file($path)) {
            throw new RuntimeException("Demo-asset ontbreekt: {$filename}");
        }

        return $this->storeUpload->handle(
            $intake,
            $installer,
            $subject,
            new UploadedFile($path, $filename, 'image/jpeg', null, true),
        );
    }

    /**
     * @param  list<array{upload: int, label: string}>  $segments
     */
    private function storePrecomputedRoute(
        Intake $intake,
        AircoConnection $connection,
        array $segments,
    ): void {
        $session = $intake->pipeRouteSessions()->create([
            'airco_connection_id' => $connection->id,
            'status' => PipeRouteStatus::Proposed,
            'confidence' => 0.87,
            'proposed_route' => $connection->segments,
            'alternative_route' => ['Rechtstreeks buitenom vanaf beide kamerwanden'],
            'uncertainties' => [],
            'missing_checks' => [],
            'next_photo_instruction' => null,
        ]);

        foreach ($segments as $index => $segment) {
            $session->segments()->create([
                'intake_upload_id' => $segment['upload'],
                'sequence' => $index + 1,
                'label' => $segment['label'],
                'photo_usable' => true,
                'route_possible' => true,
                'confidence' => 0.87,
                'analysis' => [
                    'photo_usable' => true,
                    'visible_elements' => [$segment['label']],
                    'route_possible' => true,
                    'route_segments' => $connection->segments ?? [],
                    'confidence' => 0.87,
                    'missing_information' => [],
                    'next_photo_instruction' => '',
                    'source' => 'precomputed_demo',
                ],
            ]);
        }
    }

    /**
     * @param  list<IntakeUpload>  $uploads
     */
    private function storeSynthesisRecord(Intake $intake, AiRun $run, array $uploads): void
    {
        $root = $this->dossierManager->root($intake);
        $output = $run->output ?? [];

        $this->dossierManager->record(
            intake: $intake,
            subject: $root,
            kind: DossierRecordKind::Conclusion,
            key: 'ai_dossier_synthesis',
            value: [
                'summary' => $output['summary'] ?? 'Vooraf berekend demovoorstel.',
                'exceptions' => $output['exceptions'] ?? [],
                'placement_count' => 5,
                'option_count' => 1,
                'customer_task_count' => 1,
            ],
            actorType: 'ai',
            actorId: null,
            sourceType: 'ai_run',
            sourceId: $run->id,
            method: 'precomputed_demo_synthesis',
            confidence: 0.88,
            status: DossierRecordStatus::Proposed,
            evidence: array_map(
                static fn (IntakeUpload $upload): array => [
                    'type' => 'intake_upload',
                    'id' => $upload->id,
                ],
                $uploads,
            ),
        );
    }

    private function storeProposedCustomerTask(
        Intake $intake,
        AiRun $run,
        DossierSubject $subject,
        IntakeUpload $fuseboxUpload,
    ): void {
        ContributionTask::query()->create([
            'intake_id' => $intake->id,
            'company_id' => $intake->company_id,
            'dossier_subject_id' => $subject->id,
            'intake_follow_up_item_id' => null,
            'audience' => ContributionAudience::Customer,
            'type' => FollowUpItemType::Photo,
            'prompt' => 'Maak één frontale foto van de volledige groepenkast waarop alle groepslabels scherp leesbaar zijn.',
            'decision_area_key' => 'power',
            'status' => ContributionTaskStatus::Proposed,
            'requested_by' => null,
            'meta' => [
                'source_type' => 'ai',
                'source_id' => $run->id,
                'reason' => 'De huidige synthetische voorbeeldfoto heeft reflectie op één labelregel; alleen dit detail kan de stroomofferte nog beïnvloeden.',
                'evidence_references' => ['intake_upload:'.$fuseboxUpload->id],
            ],
        ]);
    }
}
