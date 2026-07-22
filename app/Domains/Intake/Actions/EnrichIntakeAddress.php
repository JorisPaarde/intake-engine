<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Data\AddressEnrichment;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Services\PdokAddressService;
use App\Domains\Intake\Services\PdokAerialImageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class EnrichIntakeAddress
{
    public function __construct(
        private readonly PdokAddressService $pdok,
        private readonly PdokAerialImageService $aerialImages,
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
