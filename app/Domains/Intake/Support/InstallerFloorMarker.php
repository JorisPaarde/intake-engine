<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;

/**
 * Installateursmarker op AircoRoom.dimensions (ook null = gewist).
 * Prefill mag floor_level niet terugzetten zolang de marker bestaat.
 */
final class InstallerFloorMarker
{
    public static function blocksPrefill(Intake $intake, ?string $sectionInstanceKey): bool
    {
        if (! is_string($sectionInstanceKey) || $sectionInstanceKey === '') {
            return false;
        }

        $room = AircoRoom::query()
            ->where('intake_id', $intake->id)
            ->where('key', $sectionInstanceKey)
            ->first();

        if (! $room instanceof AircoRoom || ! is_array($room->dimensions)) {
            return false;
        }

        return ($room->dimensions['floor_level_source'] ?? null) === 'installer';
    }
}
