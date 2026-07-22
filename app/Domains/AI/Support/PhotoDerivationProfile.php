<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use RuntimeException;

/**
 * A named photo-analysis profile: which prompt runs on which photo question, and which
 * answers it may derive from the result (BL-020 generalised).
 *
 * A question opts in through `meta.photo_analysis` in the template config. Publishing a
 * template with an unknown profile name fails loudly rather than silently deriving nothing.
 */
final class PhotoDerivationProfile
{
    /**
     * @param  list<DerivedAnswerField>  $fields
     */
    private function __construct(
        public readonly string $name,
        public readonly string $promptName,
        public readonly array $fields,
        /**
         * Hoeveel foto's dit profiel meestuurt. Een ruimte of een meterkast is één
         * onderwerp; een leidingroute loopt door het huis en is alleen te volgen als de
         * losse opnames samen worden beoordeeld.
         */
        public readonly int $maxImages = 2,
    ) {}

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        static $profiles = null;

        if ($profiles !== null) {
            return $profiles;
        }

        $profiles = [
            'room' => new self('room', 'room_assessment', [
                DerivedAnswerField::choice('room_type', 'room_type', ['living_room', 'bedroom', 'office', 'attic']),
                DerivedAnswerField::choice('room_size_indication', 'room_size_indication', ['small', 'medium', 'large']),
                DerivedAnswerField::choice('sun_exposure', 'sun_exposure', ['low', 'medium', 'high']),
                DerivedAnswerField::choice('glass_amount', 'glass_amount', ['little', 'average', 'much']),
            ]),
            'outdoor' => new self('outdoor', 'outdoor_assessment', [
                DerivedAnswerField::choice('outdoor_location', 'outdoor_location', ['garden', 'side_passage', 'facade', 'balcony', 'flat_roof', 'pitched_roof']),
                DerivedAnswerField::choice('outdoor_mount_type', 'outdoor_mount_type', ['wall', 'ground', 'roof', 'balcony']),
                DerivedAnswerField::choice('outdoor_accessibility', 'outdoor_accessibility', ['easy_ground', 'ladder', 'scaffolding', 'restricted']),
            ]),
            'pipe_route' => new self('pipe_route', 'pipe_route_assessment', maxImages: 5, fields: [
                DerivedAnswerField::choice('pipe_route_description', 'pipe_route_description', ['along_facade', 'through_attic', 'through_room', 'short_direct']),
                DerivedAnswerField::choice('pipe_distance_indication', 'pipe_distance_indication', ['short', 'medium', 'long']),
                DerivedAnswerField::boolean('drillings_needed', 'drillings_needed'),
            ]),
        ];

        return $profiles;
    }

    public static function find(string $name): ?self
    {
        return self::all()[$name] ?? null;
    }

    public static function require(string $name): self
    {
        $profile = self::find($name);

        if (! $profile instanceof self) {
            throw new RuntimeException("Onbekend foto-analyseprofiel [{$name}].");
        }

        return $profile;
    }

    /**
     * @return list<string>
     */
    public function questionKeys(): array
    {
        return array_map(static fn (DerivedAnswerField $field): string => $field->questionKey, $this->fields);
    }
}
