<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use Illuminate\Support\Collection;

/**
 * Hergebruik van gevel-/tuin-/buitenfoto’s voor “Foto’s rondom het huis”.
 * Minimaal één bruikbare outdoor/facade-foto maakt around_house optioneel.
 */
final class OutdoorPhotoReuse
{
    /** @var list<string> */
    public const SOURCE_KEYS = [
        'outdoor_location_photos',
        'facade_overview_photo',
        'around_house_photos',
    ];

    public const TARGET_KEY = 'around_house_photos';

    /**
     * @param  Collection<int, IntakeUpload>|null  $uploads
     */
    public static function hasUsableOutdoorContext(Intake $intake, ?Collection $uploads = null): bool
    {
        $uploads ??= $intake->uploads;

        $candidates = $uploads->filter(
            static fn (IntakeUpload $upload): bool => in_array(
                $upload->question_key,
                ['outdoor_location_photos', 'facade_overview_photo'],
                true,
            ),
        );

        if ($candidates->isEmpty()) {
            return false;
        }

        return PhotoContentSatisfaction::uploadsSatisfy($candidates)
            || $candidates->contains(
                static fn (IntakeUpload $upload): bool => ! PhotoOverridePolicy::needsOverride($upload)
                    && $upload->assessment_status?->isTerminal() === true,
            );
    }

    public static function aroundHouseSatisfiedByReuse(Intake $intake): bool
    {
        if (PhotoContentSatisfaction::isSatisfied($intake, self::TARGET_KEY, null)) {
            return true;
        }

        return self::hasUsableOutdoorContext($intake);
    }
}
