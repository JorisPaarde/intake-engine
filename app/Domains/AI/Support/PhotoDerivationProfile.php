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
                new DerivedAnswerField('room_size_indication', 'room_size_indication', ['small', 'medium', 'large']),
                new DerivedAnswerField('sun_exposure', 'sun_exposure', ['low', 'medium', 'high']),
                new DerivedAnswerField('glass_amount', 'glass_amount', ['little', 'average', 'much']),
            ]),
            'outdoor' => new self('outdoor', 'outdoor_assessment', [
                new DerivedAnswerField('outdoor_mount_type', 'outdoor_mount_type', ['wall', 'ground', 'roof', 'balcony']),
                new DerivedAnswerField('outdoor_accessibility', 'outdoor_accessibility', ['easy_ground', 'ladder', 'scaffolding', 'restricted']),
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
