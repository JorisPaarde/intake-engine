<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\AircoRoom;

/**
 * Explicit rules for when ceiling height is needed for capacity decisions (BL-101).
 *
 * Height is separate from floor area: missing height does not block capacity unless
 * one of these rules applies.
 */
final class RoomHeightRequirement
{
    /**
     * Use types where ceiling height can change the capacity decision.
     *
     * @var list<string>
     */
    private const USE_TYPES_NEEDING_HEIGHT = [
        'attic',
    ];

    public function isRequired(AircoRoom $room): bool
    {
        $useType = $room->use_type;

        if (! is_string($useType) || $useType === '') {
            return false;
        }

        return in_array($useType, self::USE_TYPES_NEEDING_HEIGHT, true);
    }

    public function missingRequiredHeight(AircoRoom $room): bool
    {
        if (! $this->isRequired($room)) {
            return false;
        }

        return ! RoomDimensions::from(is_array($room->dimensions) ? $room->dimensions : null)->hasHeight();
    }
}
