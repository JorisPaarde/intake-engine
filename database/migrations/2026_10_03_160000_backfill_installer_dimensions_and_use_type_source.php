<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-116 review ronde 4/5: dimensions_source + use_type_source-backfill via airco_room_updated.
 *
 * Chunked, idempotent, MySQL-veilig. Geen schemawijziging — alleen JSON/kolomwaarden.
 * down() is bewust een no-op: markers zijn additief; rollback mag runtime-bescherming niet wissen.
 */
return new class extends Migration
{
    private const CHUNK = 200;

    public function up(): void
    {
        if (! Schema::hasTable('airco_rooms') || ! Schema::hasTable('intake_activity_events')) {
            return;
        }

        $updatedRoomIds = $this->roomIdsWithAircoRoomUpdated();
        if ($updatedRoomIds === []) {
            return;
        }

        $this->backfillDimensionsSource($updatedRoomIds);
        $this->backfillUseTypeSource($updatedRoomIds);
    }

    public function down(): void
    {
        // No-op: dimensions_source / use_type_source-markers zijn additief.
        // Rollback mag runtime-installateursbescherming niet wissen.
    }

    /**
     * @param  array<int, true>  $updatedRoomIds
     */
    private function backfillDimensionsSource(array $updatedRoomIds): void
    {
        foreach (array_chunk(array_keys($updatedRoomIds), self::CHUNK) as $chunk) {
            $rooms = DB::table('airco_rooms')
                ->whereIn('id', $chunk)
                ->where('source_type', 'template_bridge')
                ->get(['id', 'dimensions']);

            foreach ($rooms as $room) {
                $dimensions = $this->decodeJson($room->dimensions);
                if (($dimensions['dimensions_source'] ?? null) === 'installer') {
                    continue;
                }

                $dimensions['dimensions_source'] = 'installer';
                DB::table('airco_rooms')
                    ->where('id', $room->id)
                    ->update(['dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR)]);
            }
        }
    }

    /**
     * @param  array<int, true>  $updatedRoomIds
     */
    private function backfillUseTypeSource(array $updatedRoomIds): void
    {
        if (! Schema::hasColumn('airco_rooms', 'use_type_source')) {
            return;
        }

        foreach (array_chunk(array_keys($updatedRoomIds), self::CHUNK) as $chunk) {
            DB::table('airco_rooms')
                ->whereIn('id', $chunk)
                ->where('source_type', 'template_bridge')
                ->where(function ($query): void {
                    $query->whereNull('use_type_source')
                        ->orWhere('use_type_source', '!=', 'installer');
                })
                ->update(['use_type_source' => 'installer']);
        }
    }

    /**
     * @return array<int, true>
     */
    private function roomIdsWithAircoRoomUpdated(): array
    {
        $ids = [];

        DB::table('intake_activity_events')
            ->where('event', 'airco_room_updated')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($events) use (&$ids): void {
                foreach ($events as $event) {
                    $props = $this->decodeJson($event->properties);
                    $roomId = $props['room_id'] ?? null;
                    if (is_numeric($roomId)) {
                        $ids[(int) $roomId] = true;
                    }
                }
            });

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
};
