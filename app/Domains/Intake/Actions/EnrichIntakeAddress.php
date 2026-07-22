<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Data\AddressEnrichment;
use App\Domains\Intake\Data\EnergyLabel;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Services\EpOnlineService;
use App\Domains\Intake\Services\PdokAddressService;
use App\Domains\Intake\Services\PdokAerialImageService;
use App\Domains\Intake\Services\ThreeDBagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class EnrichIntakeAddress
{
    public function __construct(
        private readonly PdokAddressService $pdok,
        private readonly PdokAerialImageService $aerialImages,
        private readonly ThreeDBagService $threeDBag,
        private readonly EpOnlineService $epOnline,
        private readonly SaveIntakeAnswer $saveIntakeAnswer,
    ) {}

    public function handle(Intake $intake, ?string $lookupId = null): void
    {
        if (! (bool) config('services.pdok.enabled', true)) {
            return;
        }

        try {
            $enrichment = $this->pdok->enrich($intake, $lookupId);

            if ($enrichment === null) {
                $this->storeStatus($intake, 'not_found');

                return;
            }

            DB::transaction(fn () => $this->storeEnrichment($intake, $enrichment));
            $this->captureAerialImage($intake, $enrichment);
            $this->captureBuildingGeometry($intake, $enrichment);
            $this->captureEnergyLabel($intake, $enrichment);
        } catch (Throwable $exception) {
            $this->storeStatus($intake, 'unavailable');
            Log::warning('PDOK address enrichment failed.', [
                'intake_uuid' => $intake->uuid,
                'exception' => $exception::class,
            ]);
        }
    }

    private function captureAerialImage(Intake $intake, AddressEnrichment $data): void
    {
        if (! $this->aerialImages->enabled()) {
            return;
        }

        try {
            $capture = $this->aerialImages->capture($data->longitude, $data->latitude);

            if ($capture === null) {
                $this->storeAerialStatus($intake, 'no_location');

                return;
            }

            $disk = (string) config('filesystems.media', 'local');
            $path = 'intakes/'.$intake->uuid.'/external/pdok-aerial.jpg';

            if (! Storage::disk($disk)->put($path, $capture->binary)) {
                throw new \RuntimeException('Aerial image could not be stored.');
            }

            $this->upsertFact(
                $intake,
                'aerial_image',
                'Luchtfoto rond BAG-locatie',
                [
                    'media_disk' => $disk,
                    'media_path' => $path,
                    'mime_type' => $capture->mimeType,
                    'width' => $capture->width,
                    'height' => $capture->height,
                    'layer' => $capture->layer,
                    'bbox_epsg_3857' => $capture->bbox,
                    'ground_width_meters' => $capture->groundWidthMeters,
                    'ground_height_meters' => $capture->groundHeightMeters,
                    'center_latitude' => $data->latitude,
                    'center_longitude' => $data->longitude,
                ],
                'high',
                $capture->layer,
                PdokAerialImageService::productUrl(),
                PdokAerialImageService::sourceName(),
            );

            $intake->externalFacts()
                ->where('fact_key', 'aerial_image_status')
                ->where('source', PdokAerialImageService::sourceName())
                ->delete();

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'system',
                'actor_id' => null,
                'event' => 'aerial_image_captured',
                'properties' => [
                    'source' => PdokAerialImageService::sourceName(),
                    'layer' => $capture->layer,
                    'width' => $capture->width,
                    'height' => $capture->height,
                ],
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->storeAerialStatus($intake, 'unavailable');
            Log::warning('PDOK aerial image capture failed.', [
                'intake_uuid' => $intake->uuid,
                'exception' => $exception::class,
            ]);
        }
    }

    private function storeAerialStatus(Intake $intake, string $status): void
    {
        $this->upsertFact(
            $intake,
            'aerial_image_status',
            'Luchtfoto omgeving',
            ['status' => $status],
            'unknown',
            null,
            PdokAerialImageService::productUrl(),
            PdokAerialImageService::sourceName(),
        );
    }

    private function storeEnrichment(Intake $intake, AddressEnrichment $data): void
    {
        $intake->update([
            'address_line' => $data->addressLine,
            'address_postal_code' => $data->postalCode,
            'address_city' => $data->city,
        ]);

        $sourceUrl = rtrim((string) config('services.pdok.bag_base_url'), '/')
            .'/collections/verblijfsobject/items?f=html&identificatie='.$data->bagAddressableObjectId.'&limit=1';

        $this->upsertFact($intake, 'address_verification', 'Adrescontrole', ['status' => 'matched'], 'high', $data->bagAddressableObjectId, $sourceUrl);
        $this->upsertFact($intake, 'location', 'Locatie', [
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
            'municipality' => $data->municipality,
            'province' => $data->province,
        ], 'high', $data->bagAddressableObjectId, $sourceUrl);

        if ($data->floorAreaM2 !== null) {
            $this->upsertFact($intake, 'floor_area_m2', 'Gebruiksoppervlakte', ['number' => $data->floorAreaM2, 'unit' => 'm²'], 'high', $data->bagAddressableObjectId, $sourceUrl);
        }

        if ($data->usagePurposes !== []) {
            $this->upsertFact($intake, 'usage_purposes', 'Gebruiksdoel', ['values' => $data->usagePurposes], 'high', $data->bagAddressableObjectId, $sourceUrl);
            $this->prefillBuildingType($intake, $data->usagePurposes);
        }

        if ($data->parcelIds !== []) {
            $this->upsertFact($intake, 'parcel_ids', 'Perceelreferentie', ['values' => $data->parcelIds], 'high', $data->bagAddressableObjectId, $sourceUrl);
        }

        if ($data->buildYear !== null && $data->buildingCount === 1) {
            $buildingUrl = $data->bagBuildingId === null
                ? $sourceUrl
                : rtrim((string) config('services.pdok.bag_base_url'), '/').'/collections/pand/items?f=html&identificatie='.$data->bagBuildingId.'&limit=1';

            $this->upsertFact($intake, 'building_year', 'Bouwjaar', ['number' => $data->buildYear], 'high', $data->bagBuildingId, $buildingUrl);

            if ($this->hasQuestion($intake, 'build_year')) {
                $this->saveIntakeAnswer->handle($intake, 'build_year', null, ['number' => $data->buildYear], 'pdok');
            }
        } elseif ($data->buildingCount !== 1) {
            $this->upsertFact($intake, 'building_match', 'Koppeling aan pand', [
                'status' => 'ambiguous',
                'building_count' => $data->buildingCount,
            ], 'unknown', $data->bagAddressableObjectId, $sourceUrl);
        }

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'system',
            'actor_id' => null,
            'event' => 'address_enriched',
            'properties' => [
                'source' => PdokAddressService::sourceName(),
                'fact_keys' => $intake->externalFacts()->pluck('fact_key')->all(),
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Het geregistreerde energielabel uit EP-Online (RVO).
     *
     * Levert twee antwoorden die we anders zouden vragen:
     *
     *   - `insulation_indication` uit de energiebehoefte. Dat getal is de vraag vóór
     *     installaties, dus een betere maat voor de schil dan de labelletter — die
     *     verrekent ook zonnepanelen en warmtepomp.
     *   - `building_type` uit het geregistreerde woningtype, maar alleen als het naar een
     *     template-optie te vertalen is. Herkennen we de omschrijving niet, dan blijft de
     *     vraag staan in plaats van dat we gokken.
     *
     * De onderbouwing (labelletter én kWh/m²·jr) komt als feit in het dossier, met bron en
     * registratiedatum, zodat het afgeleide antwoord navolgbaar blijft.
     */
    private function captureEnergyLabel(Intake $intake, AddressEnrichment $data): void
    {
        try {
            $label = $this->epOnline->forAddressableObject($data->bagAddressableObjectId);

            if ($label === null) {
                return;
            }

            $sourceUrl = $this->epOnline->labelUrl($data->bagAddressableObjectId);
            $reference = $label->registeredAt;

            if ($label->energyClass !== null) {
                $this->upsertFact(
                    $intake,
                    'energy_label',
                    'Energielabel',
                    array_filter([
                        'value' => $label->energyClass,
                        'registered_at' => $label->registeredAt,
                        'valid_until' => $label->validUntil,
                    ], static fn (mixed $value): bool => $value !== null),
                    'high',
                    $reference,
                    $sourceUrl,
                    EpOnlineService::sourceName(),
                );
            }

            if ($label->energyDemandKwhM2 !== null) {
                $this->upsertFact(
                    $intake,
                    'energy_demand',
                    'Energiebehoefte (schil)',
                    ['number' => $label->energyDemandKwhM2, 'unit' => 'kWh/m²·jr'],
                    'high',
                    $reference,
                    $sourceUrl,
                    EpOnlineService::sourceName(),
                );
            }

            $this->prefillFromEnergyLabel($intake, $label);
        } catch (Throwable $exception) {
            // Geen label of een storing betekent gewoon dat de vragen blijven staan.
            Log::warning('EP-Online energy label lookup failed.', [
                'intake_uuid' => $intake->uuid,
                'exception' => $exception::class,
            ]);
        }
    }

    private function prefillFromEnergyLabel(Intake $intake, EnergyLabel $label): void
    {
        $insulation = $label->insulationIndication();

        if ($insulation !== null && $this->hasQuestion($intake, 'insulation_indication')) {
            $this->saveIntakeAnswer->handle($intake, 'insulation_indication', null, ['value' => $insulation], 'epo');
        }

        $buildingType = $label->buildingTypeOption();

        if ($buildingType !== null && $this->hasQuestion($intake, 'building_type')) {
            $this->saveIntakeAnswer->handle($intake, 'building_type', null, ['value' => $buildingType], 'epo');
        }
    }

    /**
     * Dakvorm en gevelhoogte uit de 3DBAG (CC BY 4.0 — opslaan mag, mits bron vermeld).
     *
     * Bewust alleen als context-feit: de hoogte van een pand zegt niet waar de buitenunit
     * komt te hangen, dus hier vervalt geen enkele vraag. Het helpt de installateur bij het
     * inschatten van ladder of steiger, en gaat als feit mee in de AI-context.
     *
     * Een onbetrouwbare reconstructie (`b3_kwaliteitsindicator = false`) wordt wél getoond,
     * maar met lage zekerheid — de installateur moet kunnen zien dat hij er niet op mag varen.
     */
    private function captureBuildingGeometry(Intake $intake, AddressEnrichment $data): void
    {
        if ($data->bagBuildingId === null || $data->buildingCount !== 1) {
            return;
        }

        try {
            $geometry = $this->threeDBag->fetch($data->bagBuildingId);

            if ($geometry === null) {
                return;
            }

            $confidence = $geometry->reliable ? 'high' : 'low';
            $sourceUrl = $this->threeDBag->featureUrl($data->bagBuildingId);

            if ($geometry->heightAboveGroundM !== null) {
                $this->upsertFact(
                    $intake,
                    'building_height_m',
                    'Gevelhoogte (nok boven maaiveld)',
                    ['number' => $geometry->heightAboveGroundM, 'unit' => 'm'],
                    $confidence,
                    $data->bagBuildingId,
                    $sourceUrl,
                    ThreeDBagService::sourceName(),
                );
            }

            if ($geometry->hasUsableRoofType()) {
                $this->upsertFact(
                    $intake,
                    'roof_type',
                    'Dakvorm',
                    ['value' => $geometry->roofType, 'label' => $geometry->roofTypeLabel()],
                    $confidence,
                    $data->bagBuildingId,
                    $sourceUrl,
                    ThreeDBagService::sourceName(),
                );
            }

            if ($geometry->floorCount !== null) {
                $this->upsertFact(
                    $intake,
                    'floor_count',
                    'Aantal bouwlagen',
                    ['number' => $geometry->floorCount],
                    $confidence,
                    $data->bagBuildingId,
                    $sourceUrl,
                    ThreeDBagService::sourceName(),
                );
            }
        } catch (Throwable $exception) {
            // 3DBAG is een aanvulling, nooit een blokkade voor de opname.
            Log::warning('3DBAG building geometry lookup failed.', [
                'intake_uuid' => $intake->uuid,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Het BAG-gebruiksdoel onderscheidt wél wonen van niet-wonen, maar niet tussen rijtjeshuis,
     * hoekwoning en vrijstaand. Alleen het eenduidige geval wordt overgenomen: geen enkele
     * woonfunctie betekent `commercial`. Bij twijfel of gemengd gebruik blijft de vraag staan —
     * een fout voorzet kost de installateur meer dan één extra vraag.
     *
     * @param  list<string>  $usagePurposes
     */
    private function prefillBuildingType(Intake $intake, array $usagePurposes): void
    {
        if (! $this->hasQuestion($intake, 'building_type')) {
            return;
        }

        $normalized = array_map(static fn (string $purpose): string => mb_strtolower(trim($purpose)), $usagePurposes);

        if ($normalized === [] || in_array('woonfunctie', $normalized, true)) {
            return;
        }

        $this->saveIntakeAnswer->handle($intake, 'building_type', null, ['value' => 'commercial'], 'pdok');
    }

    private function storeStatus(Intake $intake, string $status): void
    {
        $this->upsertFact(
            $intake,
            'address_verification',
            'Adrescontrole',
            ['status' => $status],
            'unknown',
            null,
            'https://api.pdok.nl/',
        );
    }

    private function hasQuestion(Intake $intake, string $questionKey): bool
    {
        $intake->loadMissing('templateVersion.sections.questions');

        return $intake->templateVersion->sections
            ->flatMap(fn ($section) => $section->questions)
            ->contains('key', $questionKey);
    }

    /** @param array<string, mixed> $value */
    private function upsertFact(
        Intake $intake,
        string $key,
        string $label,
        array $value,
        string $confidence,
        ?string $reference,
        ?string $sourceUrl,
        ?string $source = null,
    ): void {
        IntakeExternalFact::query()->updateOrCreate(
            [
                'intake_id' => $intake->id,
                'fact_key' => $key,
                'source' => $source ?? PdokAddressService::sourceName(),
            ],
            [
                'label' => $label,
                'value' => $value,
                'source_reference' => $reference,
                'source_url' => $sourceUrl,
                'confidence' => $confidence,
                'captured_at' => now(),
            ],
        );
    }
}
