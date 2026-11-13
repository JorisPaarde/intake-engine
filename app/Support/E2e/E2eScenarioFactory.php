<?php

declare(strict_types=1);

namespace App\Support\E2e;

use App\Domains\AI\Support\E2eAiScenario;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds deterministic intakes for Playwright customer-flow scenarios.
 */
final class E2eScenarioFactory
{
    public function __construct(
        private readonly SaveIntakeAnswer $saveAnswer,
        private readonly DossierManager $dossierManager,
        private readonly CreateCustomerContributionRequest $createContribution,
        private readonly StoreIntakeUpload $storeUpload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(string $scenario): array
    {
        return match ($scenario) {
            'wizard-happy-path', 'empty-living-room' => $this->emptyLivingRoom(),
            'fusebox-upload' => $this->fuseboxUploadReady(),
            'follow-up-mismatch' => $this->followUpMismatch(),
            'progress-empty' => $this->progressEmpty(),
            'drain-facade' => $this->drainAndFacade(),
            'feedback' => $this->feedbackReady(),
            'room-name-autosave' => $this->roomNameAutosave(),
            'resume-after-known-edit' => $this->resumeAfterKnownEdit(),
            'n37-edit-known-area' => $this->n37EditKnownArea(),
            'n30-accessibility-catchup' => $this->n30AccessibilityCatchup(),
            'n3-drain-choice-upload' => $this->n3DrainChoiceUpload(),
            'differs-per-room-floors' => $this->differsPerRoomFloors(),
            'bl165-differs-sun-floor-glass' => $this->bl165DiffersSunFloorGlass(),
            'bl166-commercial' => $this->bl166Commercial(),
            'bl166-landlord' => $this->bl166Landlord(),
            'bl166-bedroom-indoor' => $this->bl166BedroomIndoor(),
            'bl166-noise' => $this->bl166Noise(),
            'bl166-closing' => $this->bl166Closing(),
            'bl166-thank-you' => $this->bl166ThankYou(),
            'bl169-step1' => $this->bl169Step1(),
            'bl169-known-summary' => $this->bl169KnownSummary(),
            'bl169-closing' => $this->bl166Closing(),
            'bl169-afronden' => $this->bl169Afronden(),
            default => throw new \InvalidArgumentException('Unknown E2E scenario: '.$scenario),
        };
    }

    /**
     * BL-166: bedrijfspand (geen woonfunctie) → pandgegevens + melding.
     *
     * @return array<string, mixed>
     */
    private function bl166Commercial(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'BL166 Bedrijfspand',
            'customer_email' => 'bl166-commercial@example.com',
            'status' => IntakeStatus::Sent,
            'address_line' => 'Grote Markt 2',
            'address_postal_code' => '2011RD',
            'address_house_number' => '2',
            'address_city' => 'Haarlem',
            // Leeg: land op stap 1 zodat pandregel zichtbaar is (BL-169).
            'request_reason' => null,
        ]);

        IntakeExternalFact::query()->create([
            'intake_id' => $intake->id,
            'fact_key' => 'usage_purposes',
            'label' => 'Gebruiksdoel',
            'value' => ['values' => ['bijeenkomstfunctie', 'winkelfunctie']],
            'source' => 'PDOK / BAG',
            'confidence' => 'high',
            'captured_at' => now(),
        ]);
        IntakeExternalFact::query()->create([
            'intake_id' => $intake->id,
            'fact_key' => 'building_year',
            'label' => 'Bouwjaar',
            'value' => ['number' => 1900],
            'source' => 'PDOK / BAG',
            'confidence' => 'high',
            'captured_at' => now(),
        ]);
        IntakeExternalFact::query()->create([
            'intake_id' => $intake->id,
            'fact_key' => 'energy_label',
            'label' => 'Energielabel',
            'value' => ['value' => 'B'],
            'source' => 'demo-label',
            'confidence' => 'high',
            'captured_at' => now(),
        ]);

        $this->saveAnswer->handle($intake, 'build_year', null, ['number' => 1900], 'pdok');
        $this->saveAnswer->handle($intake, 'building_type', null, ['value' => 'commercial'], 'pdok');
        $this->saveAnswer->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        // BL-169: pandregel alleen op stap 1 (request_reason).
        $intake->update([
            'current_section_key' => 'request',
            'current_question_key' => 'request_reason',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'bl166-commercial');
    }

    /**
     * BL-169: stap 1 met pandregel + leeg verplicht reden-veld.
     *
     * @return array<string, mixed>
     */
    private function bl169Step1(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'BL169 Stap1',
            'customer_email' => 'bl169-step1@example.com',
            'status' => IntakeStatus::Sent,
            'address_line' => 'Lepelstraat 1',
            'address_postal_code' => '2011RD',
            'address_house_number' => '1',
            'address_city' => 'Haarlem',
        ]);

        IntakeExternalFact::query()->create([
            'intake_id' => $intake->id,
            'fact_key' => 'building_year',
            'label' => 'Bouwjaar',
            'value' => ['number' => 1920],
            'source' => 'PDOK / BAG',
            'confidence' => 'high',
            'captured_at' => now(),
        ]);
        $this->saveAnswer->handle($intake, 'build_year', null, ['number' => 1920], 'pdok');
        $this->saveAnswer->handle($intake, 'building_type', null, ['value' => 'terraced'], 'pdok');

        $intake->update([
            'current_section_key' => 'request',
            'current_question_key' => 'request_reason',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'bl169-step1');
    }

    /**
     * BL-169: known-summary met twee ruimtes (één rij per ruimte).
     *
     * @return array<string, mixed>
     */
    private function bl169KnownSummary(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'BL169 Known',
            'customer_email' => 'bl169-known@example.com',
            'status' => IntakeStatus::InProgress,
            'address_postal_code' => '2011RD',
            'address_city' => 'Haarlem',
            'request_reason' => 'Airco in woonkamer en slaapkamer.',
        ]);

        $this->saveAnswer->handle($intake, 'cooling_heating', null, ['value' => 'cooling'], PrefillSources::AI_TEXT);
        $this->saveAnswer->handle($intake, 'indoor_unit_count', null, ['number' => 2], PrefillSources::AI_TEXT);
        $this->saveAnswer->handle($intake, 'room_type', 'room-1', ['value' => 'living_room'], PrefillSources::AI_TEXT);
        $this->saveAnswer->handle($intake, 'room_name', 'room-1', ['text' => 'Woonkamer'], PrefillSources::AI_TEXT);
        $this->saveAnswer->handle($intake, 'room_type', 'room-2', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
        $this->saveAnswer->handle($intake, 'room_name', 'room-2', ['text' => 'Slaapkamer'], PrefillSources::AI_TEXT);
        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $intake->update([
            'current_section_key' => 'request',
            'current_question_key' => '_known_summary',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'bl169-known-summary');
    }

    /**
     * BL-169: land op Afronden (truth_confirmation).
     *
     * @return array<string, mixed>
     */
    private function bl169Afronden(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'BL169 Afronden',
            'customer_email' => 'bl169-afronden@example.com',
            'status' => IntakeStatus::InProgress,
            'request_reason' => 'Airco, klaar om te versturen.',
        ]);

        $this->saveAnswer->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
        $this->saveAnswer->handle($intake, 'privacy_consent', null, ['bool' => true]);
        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $intake->update([
            'current_section_key' => 'closing',
            'current_question_key' => 'truth_confirmation',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'bl169-afronden');
    }

    /**
     * BL-166: huur → verhuurderstoestemming.
     *
     * @return array<string, mixed>
     */
    private function bl166Landlord(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'BL166 Huur',
            'customer_email' => 'bl166-landlord@example.com',
            'status' => IntakeStatus::InProgress,
            'request_reason' => 'Airco in huurwoning.',
        ]);

        $this->saveAnswer->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
        $this->saveAnswer->handle($intake, 'cooling_heating', null, ['value' => 'cooling'], PrefillSources::AI_TEXT);
        $this->saveAnswer->handle($intake, 'ownership', null, ['value' => 'rented']);
        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $intake->update([
            'current_section_key' => 'building',
            'current_question_key' => 'landlord_permission',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'bl166-landlord');
    }

    /**
     * BL-166: slaapkamer → binnenunit-voorbeeld zonder “bank”.
     *
     * @return array<string, mixed>
     */
    private function bl166BedroomIndoor(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'BL166 Slaapkamer',
            'customer_email' => 'bl166-bedroom@example.com',
            'status' => IntakeStatus::InProgress,
            'request_reason' => 'Airco in de slaapkamer.',
        ]);

        $this->saveAnswer->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
        $this->saveAnswer->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $intake->update([
            'current_section_key' => 'rooms',
            'current_question_key' => 'preferred_indoor_location',
            'current_section_instance_key' => 'room-1',
        ]);

        return $this->payload($intake, 'bl166-bedroom-indoor');
    }

    /**
     * BL-166: geluidsvraag met juiste helptekst.
     *
     * @return array<string, mixed>
     */
    private function bl166Noise(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'BL166 Geluid',
            'customer_email' => 'bl166-noise@example.com',
            'status' => IntakeStatus::InProgress,
            'request_reason' => 'Airco met buitenunit bij buren.',
        ]);

        $this->saveAnswer->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
        $this->saveAnswer->handle($intake, 'outdoor_location', null, ['value' => 'garden'], PrefillSources::AI_TEXT);
        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $intake->update([
            'current_section_key' => 'outdoor_unit',
            'current_question_key' => 'noise_sensitive',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'bl166-noise');
    }

    /**
     * BL-166: merk/planning — één merkuitleg.
     *
     * @return array<string, mixed>
     */
    private function bl166Closing(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'BL166 Closing',
            'customer_email' => 'bl166-closing@example.com',
            'status' => IntakeStatus::InProgress,
            'request_reason' => 'Airco, geen haast.',
        ]);

        $this->saveAnswer->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $intake->update([
            'current_section_key' => 'closing',
            'current_question_key' => 'brand_preference',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'bl166-closing');
    }

    /**
     * BL-166: bedankscherm (follow-up-achtige complete in-sessie via lege follow-up? —
     * hier: hoofdwizard met genoeg antwoorden; Playwright rondt af of landt op bedankt
     * via Livewire set is niet beschikbaar — we zetten status niet op Completed).
     *
     * @return array<string, mixed>
     */
    private function bl166ThankYou(): array
    {
        // Gebruik happy-path-basis; Playwright klikt Afronden na skip van rest.
        return $this->emptyLivingRoom();
    }

    /**
     * Klanttest 11 nov run 3: 3 slaapkamers 3×5, cursor op gedeelde verdiepingsvraag.
     *
     * @return array<string, mixed>
     */
    private function differsPerRoomFloors(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Verschilt Per Ruimte',
            'customer_email' => 'e2e-differs@example.com',
            'status' => IntakeStatus::InProgress,
            'address_postal_code' => '2037GR',
            'address_house_number' => '273',
            'address_city' => 'Haarlem',
            'address_line' => 'Teststraat 273',
            'request_reason' => 'Ik wil airco in 3 slaapkamers van ongeveer 3 bij 5 meter, koelen en verwarmen.',
        ]);

        $stated = static function (
            SaveIntakeAnswer $save,
            Intake $intake,
            string $key,
            ?string $instance,
            array $value,
            ?string $evidence = null,
        ): void {
            $save->handle(
                $intake,
                $key,
                $instance,
                $value,
                PrefillSources::REQUEST_TEXT,
                factProvenance: FactProvenance::Stated,
                factConfidence: 100,
                factEvidence: $evidence,
                factSource: 'klantantwoord',
            );
        };

        $stated($this->saveAnswer, $intake, 'indoor_unit_count', null, ['number' => 3], '3 slaapkamers');
        $stated($this->saveAnswer, $intake, 'cooling_heating', null, ['value' => 'both'], 'koelen en verwarmen');
        $stated($this->saveAnswer, $intake, 'ownership', null, ['value' => 'owned']);
        $stated($this->saveAnswer, $intake, 'building_type', null, ['value' => 'terraced']);

        foreach (['room-1', 'room-2', 'room-3'] as $room) {
            $stated($this->saveAnswer, $intake, 'room_type', $room, ['value' => 'bedroom'], 'slaapkamers');
            $stated($this->saveAnswer, $intake, 'room_length_m', $room, ['number' => 3], '3 bij 5');
            $stated($this->saveAnswer, $intake, 'room_width_m', $room, ['number' => 5], '3 bij 5');
            $this->saveAnswer->handle(
                $intake,
                'room_area_m2',
                $room,
                ['number' => 15],
                PrefillSources::DERIVED_LXW,
                factConfidence: 100,
                factSource: 'afgeleid',
            );
        }

        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $intake->update([
            'current_section_key' => 'rooms',
            'current_question_key' => 'floor_level',
            'current_section_instance_key' => '_shared',
        ]);

        return $this->payload($intake->fresh() ?? $intake, 'differs-per-room-floors', [
            'target_question_key' => 'floor_level',
        ]);
    }

    /**
     * BL-168: cursor op gedeelde zon (vóór glas/verdieping in sort_order), plafond al beantwoord.
     * Reproduceert: Verschilt per ruimte op zon mag glas/verdieping niet stil overslaan.
     *
     * @return array<string, mixed>
     */
    private function bl165DiffersSunFloorGlass(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E BL165 Zon Glas Verdieping',
            'customer_email' => 'e2e-bl165@example.com',
            'status' => IntakeStatus::InProgress,
            'address_postal_code' => '2037GR',
            'address_house_number' => '273',
            'address_city' => 'Haarlem',
            'address_line' => 'Bernadottelaan 273',
            'request_reason' => 'Ik wil airco in 3 slaapkamers van ongeveer 3 bij 5 meter, koelen en verwarmen.',
        ]);

        $stated = static function (
            SaveIntakeAnswer $save,
            Intake $intake,
            string $key,
            ?string $instance,
            array $value,
            ?string $evidence = null,
        ): void {
            $save->handle(
                $intake,
                $key,
                $instance,
                $value,
                PrefillSources::REQUEST_TEXT,
                factProvenance: FactProvenance::Stated,
                factConfidence: 100,
                factEvidence: $evidence,
                factSource: 'klantantwoord',
            );
        };

        $stated($this->saveAnswer, $intake, 'privacy_consent', null, ['bool' => true]);
        $stated($this->saveAnswer, $intake, 'indoor_unit_count', null, ['number' => 3], '3 slaapkamers');
        $stated($this->saveAnswer, $intake, 'cooling_heating', null, ['value' => 'both'], 'koelen en verwarmen');
        $stated($this->saveAnswer, $intake, 'ownership', null, ['value' => 'owned']);
        $stated($this->saveAnswer, $intake, 'building_type', null, ['value' => 'terraced']);

        foreach (['room-1', 'room-2', 'room-3'] as $room) {
            $stated($this->saveAnswer, $intake, 'room_type', $room, ['value' => 'bedroom'], 'slaapkamers');
            $stated($this->saveAnswer, $intake, 'room_length_m', $room, ['number' => 3], '3 bij 5');
            $stated($this->saveAnswer, $intake, 'room_width_m', $room, ['number' => 5], '3 bij 5');
            $this->saveAnswer->handle(
                $intake,
                'room_area_m2',
                $room,
                ['number' => 15],
                PrefillSources::DERIVED_LXW,
                factConfidence: 100,
                factSource: 'afgeleid',
            );
            $stated($this->saveAnswer, $intake, 'ceiling_height_m', $room, ['number' => 2.5]);
        }

        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $intake->update([
            'current_section_key' => 'rooms',
            'current_question_key' => 'sun_exposure',
            'current_section_instance_key' => '_shared',
        ]);

        return $this->payload($intake->fresh() ?? $intake, 'bl165-differs-sun-floor-glass', [
            'target_question_key' => 'sun_exposure',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyLivingRoom(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Woonkamer',
            'customer_email' => 'e2e-woonkamer@example.com',
            'is_demo' => true,
            'request_reason' => 'Airco in de woonkamer, koelen en verwarmen, vrijstaande woning.',
        ]);

        $this->seedLivingRoomKnown($intake);

        return $this->payload($intake, 'empty-living-room');
    }

    /**
     * @return array<string, mixed>
     */
    private function fuseboxUploadReady(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Meterkast',
            'customer_email' => 'e2e-meterkast@example.com',
        ]);

        $this->seedLivingRoomKnown($intake);
        $this->seedThroughFusebox($intake);

        return $this->payload($intake, 'fusebox-upload', [
            'target_question_key' => 'fusebox_photo',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function followUpMismatch(): array
    {
        E2eAiScenario::set(E2eAiScenario::WRONG_SUBJECT);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Aanvulling',
            'customer_email' => 'e2e-aanvulling@example.com',
            'status' => IntakeStatus::InProgress,
        ]);

        $this->seedLivingRoomKnown($intake);
        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $user = User::query()->findOrFail($intake->created_by);
        $round = $this->createContribution->handle($intake->fresh() ?? $intake, $user, [[
            'type' => FollowUpItemType::Photo,
            'prompt' => 'Maak een duidelijke foto van de meterkast voor de stroomtoevoer.',
            'decision_area_key' => 'power',
        ], [
            'type' => FollowUpItemType::Photo,
            'prompt' => 'Maak een scherpe, goed belichte foto van de meterkast (niet te klein).',
            'decision_area_key' => 'power',
        ]]);

        $intake->refresh();

        return $this->payload($intake, 'follow-up-mismatch', [
            'follow_up_round_id' => $round->id,
            'follow_up_item_ids' => $round->items()->pluck('id')->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function progressEmpty(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Voortgang',
            'customer_email' => 'e2e-voortgang@example.com',
        ]);

        // Prefill rooms so known-summary exists, but leave customer work open.
        $this->seedLivingRoomKnown($intake);

        return $this->payload($intake, 'progress-empty');
    }

    /**
     * @return array<string, mixed>
     */
    private function drainAndFacade(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Afvoer',
            'customer_email' => 'e2e-afvoer@example.com',
        ]);

        $this->seedLivingRoomKnown($intake);
        $this->seedThroughFusebox($intake);

        // Start on the drain questions — do not prefill drain_location (test answers it).
        $intake->update([
            'current_section_key' => 'condensate',
            'current_question_key' => 'drain_location',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'drain-facade', [
            'target_question_key' => 'drain_location',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function feedbackReady(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Feedback',
            'customer_email' => 'e2e-feedback@example.com',
        ]);

        $this->seedLivingRoomKnown($intake);

        // Start direct op ruimtefoto’s (niet known-summary) zodat de test niet vastloopt
        // op “Klopt, verder” / Volgende-accessible-name.
        $intake->update([
            'current_section_key' => 'rooms',
            'current_question_key' => 'room_photos',
            'current_section_instance_key' => 'room-1',
        ]);

        return $this->payload($intake, 'feedback', [
            'target_question_key' => 'room_photos',
        ]);
    }

    /**
     * Two bedrooms at preferred_indoor_location: blur/autosave must keep the question sticky (BL-140).
     * room_name stays hidden (generated Slaapkamer N); Volgende lands on wall_outlet_photo.
     *
     * @return array<string, mixed>
     */
    private function roomNameAutosave(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E Kamernaam',
            'customer_email' => 'e2e-kamernaam@example.com',
            'request_reason' => 'Twee slaapkamers koelen',
        ]);

        $this->saveAnswer->handle($intake, 'indoor_unit_count', null, ['number' => 2], PrefillSources::REQUEST_TEXT);
        $this->saveAnswer->handle($intake, 'cooling_heating', null, ['value' => 'both'], PrefillSources::REQUEST_TEXT);
        $this->saveAnswer->handle($intake, 'building_type', null, ['value' => 'detached'], PrefillSources::REQUEST_TEXT);
        $this->saveAnswer->handle($intake, 'ownership', null, ['value' => 'owned'], PrefillSources::REQUEST_TEXT);

        foreach (['room-1', 'room-2'] as $instance) {
            foreach ([
                ['room_type', ['value' => 'bedroom'], PrefillSources::AI_TEXT],
                ['room_size_indication', ['value' => 'medium'], PrefillSources::AI_TEXT],
                ['room_length_m', ['number' => 4.0], PrefillSources::AI_TEXT],
                ['room_width_m', ['number' => 3.0], PrefillSources::AI_TEXT],
                ['room_area_m2', ['number' => 12.0], PrefillSources::AI_TEXT],
                ['ceiling_height_m', ['number' => 2.5], PrefillSources::AI_TEXT],
                ['sun_exposure', ['value' => 'medium'], PrefillSources::AI_TEXT],
                ['glass_amount', ['value' => 'average'], PrefillSources::AI_TEXT],
                ['glazing_type', ['value' => 'double'], PrefillSources::AI_TEXT],
                ['floor_level', ['value' => '1'], PrefillSources::AI_TEXT],
                ['room_outlet_status', ['value' => 'needs_photo'], PrefillSources::AI_PHOTO],
            ] as [$key, $value, $source]) {
                $this->saveAnswer->handle($intake, $key, $instance, $value, $source);
            }

            foreach (['room_photos', 'indoor_unit_position_photo'] as $photoKey) {
                $upload = $this->storeUpload->handle(
                    $intake,
                    $photoKey,
                    $instance,
                    $this->fixtureUpload('woonkamer-1440.jpg'),
                );
                $upload->updateQuietly([
                    'usability_verdict' => PhotoUsabilityVerdict::Ok,
                    'assessment_status' => PhotoAssessmentStatus::Assessed,
                    'content_assessment' => [
                        'status' => 'ok',
                        'expected_subject' => 'room',
                        'detected_subject' => 'room',
                        'customer_message' => null,
                    ],
                ]);
            }
        }

        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $intake->update([
            'current_section_key' => 'rooms',
            'current_question_key' => 'preferred_indoor_location',
            'current_section_instance_key' => 'room-1',
        ]);

        return $this->payload($intake, 'room-name-autosave', [
            'target_question_key' => 'preferred_indoor_location',
        ]);
    }

    /**
     * Known-summary with three rooms/floors prefills; cooling_heating left unanswered.
     * Used to assert Wijzigen → refresh never skips required early steps.
     *
     * @return array<string, mixed>
     */
    private function resumeAfterKnownEdit(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->seedResumeKnownRooms([
            'customer_name' => 'E2E Resume',
            'customer_email' => 'e2e-resume@example.com',
        ]);

        return $this->payload($intake, 'resume-after-known-edit', [
            'target_question_key' => 'cooling_heating',
        ]);
    }

    /**
     * N37: trusted oppervlak (m²) in known-summary — Wijzigen moet matenscherm + m² openen.
     *
     * @return array<string, mixed>
     */
    private function n37EditKnownArea(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E N37',
            'customer_email' => 'e2e-n37@example.com',
            'request_reason' => 'Airco in de woonkamer (ca. 30 m2, begane grond).',
        ]);

        $this->saveAnswer->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::REQUEST_TEXT);
        $this->saveAnswer->handle($intake, 'cooling_heating', null, ['value' => 'cooling'], PrefillSources::REQUEST_TEXT);
        $this->saveAnswer->handle($intake, 'room_type', 'room-1', ['value' => 'living_room'], PrefillSources::REQUEST_TEXT);
        $this->saveAnswer->handle(
            $intake,
            'room_area_m2',
            'room-1',
            ['number' => 30],
            PrefillSources::REQUEST_TEXT,
            FactProvenance::Stated,
            90,
            'ca. 30 m2',
        );
        $this->saveAnswer->handle($intake, 'floor_level', 'room-1', ['value' => '0'], PrefillSources::REQUEST_TEXT);

        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        $intake->update([
            'current_section_key' => 'request',
            'current_question_key' => '_known_summary',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'n37-edit-known-area', [
            'target_question_key' => 'room_area_m2',
        ]);
    }

    /**
     * N30: outdoor_accessibility open while cursor already past it (noise_sensitive).
     * Volgende must catch up to bereikbaarheid before ownership.
     *
     * @return array<string, mixed>
     */
    private function n30AccessibilityCatchup(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E N30',
            'customer_email' => 'e2e-n30@example.com',
        ]);

        $this->seedLivingRoomKnown($intake);
        $this->seedThroughFusebox($intake);

        foreach (['room_photos' => 'room-1', 'outdoor_location_photos' => null] as $photoKey => $instance) {
            $upload = $this->storeUpload->handle(
                $intake,
                $photoKey,
                $instance,
                $this->fixtureUpload($photoKey === 'room_photos' ? 'woonkamer-1440.jpg' : 'buitenunit-flow.jpeg'),
            );
            $upload->updateQuietly([
                'usability_verdict' => PhotoUsabilityVerdict::Ok,
                'assessment_status' => PhotoAssessmentStatus::Assessed,
                'content_assessment' => [
                    'status' => 'ok',
                    'expected_subject' => $photoKey === 'room_photos' ? 'room' : 'outdoor_location',
                    'detected_subject' => $photoKey === 'room_photos' ? 'room' : 'outdoor_location',
                    'customer_message' => null,
                ],
            ]);
        }

        // Repro: accessibility was skipped (prefill), then cleared — still past that step.
        // Delete after upload so FakeAi photo-derive cannot recreate the answer.
        // noise_sensitive must be unanswered so it stays in the step list (ai_text skip).
        $intake->answers()->whereIn('question_key', ['outdoor_accessibility', 'noise_sensitive'])->delete();

        $intake->update([
            'current_section_key' => 'outdoor_unit',
            'current_question_key' => 'noise_sensitive',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'n30-accessibility-catchup', [
            'target_question_key' => 'noise_sensitive',
        ]);
    }

    /**
     * N3: start on drain_nearby group with no drain_location yet (choice + immediate upload).
     *
     * @return array<string, mixed>
     */
    private function n3DrainChoiceUpload(): array
    {
        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);

        $intake = $this->baseIntake([
            'customer_name' => 'E2E N3',
            'customer_email' => 'e2e-n3@example.com',
        ]);

        $this->seedLivingRoomKnown($intake);
        $this->seedThroughFusebox($intake);

        $intake->answers()->where('question_key', 'drain_location')->delete();

        $intake->update([
            'current_section_key' => 'condensate',
            'current_question_key' => 'drain_location',
            'current_section_instance_key' => null,
        ]);

        return $this->payload($intake, 'n3-drain-choice-upload', [
            'target_question_key' => 'drain_location',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedResumeKnownRooms(array $overrides = []): Intake
    {
        // Avoid cooling/heating keywords so the offline local parser (text-AI off)
        // does not fill cooling_heating on wizard mount — that question must stay open.
        $intake = $this->baseIntake(array_merge([
            'request_reason' => 'Drie ruimtes: woonkamer, slaapkamer en zolder.',
        ], $overrides));

        $this->saveAnswer->handle($intake, 'indoor_unit_count', null, ['number' => 3], PrefillSources::AI_TEXT);

        foreach (['room-1', 'room-2', 'room-3'] as $i => $room) {
            $this->saveAnswer->handle(
                $intake,
                'room_type',
                $room,
                ['value' => $i === 0 ? 'living_room' : 'bedroom'],
                PrefillSources::AI_TEXT,
            );
            $this->saveAnswer->handle(
                $intake,
                'floor_level',
                $room,
                ['value' => (string) $i],
                PrefillSources::AI_TEXT,
            );
        }

        $this->dossierManager->initialize($intake->fresh() ?? $intake);

        // Guard: drop cooling if any mount-time derive already wrote it.
        $intake->answers()->where('question_key', 'cooling_heating')->delete();

        return $intake->fresh() ?? $intake;
    }

    /**
     * Seed-time photo from a git-tracked fixture (not tests/e2e/fixtures/*.jpg — those are generated).
     */
    private function fixtureUpload(string $name): UploadedFile
    {
        $path = base_path('tests/fixtures/klanttest-20261002/'.$name);

        if (! is_file($path)) {
            throw new \RuntimeException('E2E fixture ontbreekt: '.$name);
        }

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function baseIntake(array $overrides = []): Intake
    {
        $company = Company::query()->firstOrCreate(
            ['slug' => 'e2e-installateur'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'E2E Installateur',
                'primary_color' => Company::DEFAULT_PRIMARY,
                'accent_color' => Company::DEFAULT_ACCENT,
                'on_primary_color' => Company::DEFAULT_ON_PRIMARY,
            ],
        );

        $user = User::query()->updateOrCreate(
            ['email' => 'e2e-installateur@example.com'],
            [
                'company_id' => $company->id,
                'name' => 'E2E Installateur',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

        $requestReason = $overrides['request_reason'] ?? null;
        unset($overrides['request_reason']);

        $intake = Intake::factory()->create(array_merge([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'intake_template_version_id' => $version->id,
            'status' => IntakeStatus::Sent,
            'customer_name' => 'E2E Klant',
            'customer_email' => 'e2e-klant@example.com',
            'address_line' => 'Teststraat 1',
            'address_city' => 'Haarlem',
            'address_postal_code' => '2037GR',
            'address_house_number' => '273',
            'access_token' => Str::random(64),
            'token_expires_at' => now()->addDays(14),
            'is_demo' => true,
        ], $overrides));

        if (is_string($requestReason) && $requestReason !== '') {
            $this->saveAnswer->handle($intake, 'request_reason', null, ['text' => $requestReason], PrefillSources::REQUEST_TEXT);
        }

        return $intake->fresh() ?? $intake;
    }

    private function seedLivingRoomKnown(Intake $intake): void
    {
        $pairs = [
            ['request_reason', null, ['text' => 'Airco in de woonkamer voor koelen en verwarmen.']],
            ['indoor_unit_count', null, ['number' => 1]],
            ['cooling_heating', null, ['value' => 'both']],
            ['building_type', null, ['value' => 'detached']],
            ['room_type', 'room-1', ['value' => 'living_room']],
            ['room_name', 'room-1', ['text' => 'Woonkamer']],
            ['ownership', null, ['value' => 'owned']],
        ];

        foreach ($pairs as [$key, $instance, $value]) {
            $this->saveAnswer->handle($intake, $key, $instance, $value, PrefillSources::REQUEST_TEXT);
        }

        $this->dossierManager->initialize($intake->fresh() ?? $intake);
    }

    private function seedThroughFusebox(Intake $intake): void
    {
        $photoPrefills = [
            ['room_size_indication', 'room-1', ['value' => 'large']],
            ['sun_exposure', 'room-1', ['value' => 'medium']],
            ['glass_amount', 'room-1', ['value' => 'average']],
            ['glazing_type', 'room-1', ['value' => 'double']],
            ['room_outlet_status', 'room-1', ['value' => 'present']],
            ['room_extra_overview_needed', 'room-1', ['value' => 'complete']],
            ['room_length_m', 'room-1', ['number' => 5.0]],
            ['room_width_m', 'room-1', ['number' => 4.0]],
            ['room_area_m2', 'room-1', ['number' => 20.0]],
            ['ceiling_height_m', 'room-1', ['number' => 2.6]],
            ['floor_level', 'room-1', ['value' => '0']],
            ['preferred_indoor_location', 'room-1', ['text' => 'Aan de buitenmuur boven de bank']],
            ['outdoor_location', null, ['value' => 'garden']],
            ['outdoor_accessibility', null, ['value' => 'easy_ground']],
            ['noise_sensitive', null, ['bool' => false]],
            ['build_year', null, ['number' => 1998]],
            ['insulation_indication', null, ['value' => 'average']],
            ['floor_insulation', null, ['value' => 'unknown']],
            ['crawl_space_present', null, ['bool' => false]],
            ['pipe_visibility', null, ['value' => 'unknown']],
        ];

        foreach ($photoPrefills as [$key, $instance, $value]) {
            $this->saveAnswer->handle($intake, $key, $instance, $value, PrefillSources::AI_TEXT);
        }

        // Skip photo questions that would block reaching fusebox by marking them answered
        // via empty optional skip where the template allows — otherwise leave for the test.
        $intake->update([
            'current_section_key' => 'electrical',
            'current_question_key' => 'fusebox_photo',
            'current_section_instance_key' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(Intake $intake, string $scenario, array $extra = []): array
    {
        $fresh = $intake->fresh() ?? $intake;

        return array_merge([
            'scenario' => $scenario,
            'intake_id' => $fresh->id,
            'access_token' => $fresh->access_token,
            'customer_url' => url('/o/'.$fresh->access_token),
            'ai_scenario' => E2eAiScenario::get(),
        ], $extra);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function uploadSummaries(Intake $intake): array
    {
        return $intake->uploads()
            ->orderBy('id')
            ->get()
            ->map(function (IntakeUpload $upload): array {
                $width = null;
                $height = null;
                $disk = $upload->disk ?: (string) config('filesystems.media', 'local');
                $path = $upload->path;
                if ($path !== '' && Storage::disk($disk)->exists($path)) {
                    $bytes = Storage::disk($disk)->get($path);
                    if (is_string($bytes) && $bytes !== '') {
                        $info = @getimagesizefromstring($bytes);
                        if (is_array($info)) {
                            $width = (int) $info[0];
                            $height = (int) $info[1];
                        }
                    }
                }

                return [
                    'id' => $upload->id,
                    'question_key' => $upload->question_key,
                    'section_instance_key' => $upload->section_instance_key,
                    'original_filename' => $upload->original_filename,
                    'size_bytes' => $upload->size_bytes,
                    'width' => $width,
                    'height' => $height,
                    'original_width' => is_array($upload->processing_timings)
                        ? ($upload->processing_timings['original_width'] ?? null)
                        : null,
                    'original_height' => is_array($upload->processing_timings)
                        ? ($upload->processing_timings['original_height'] ?? null)
                        : null,
                    'assessment_status' => $upload->assessment_status?->value,
                    'usability_verdict' => $upload->usability_verdict?->value,
                    'checksum' => $upload->checksum,
                ];
            })
            ->values()
            ->all();
    }
}
