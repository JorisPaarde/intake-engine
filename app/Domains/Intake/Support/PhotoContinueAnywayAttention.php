<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DecisionReadinessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Systeemaandachtspunt wanneer de klant ‘Toch doorgaan’ koos bij afgekeurde foto’s.
 *
 * Groepeert uitsluitend op question_key + section_instance_key (geen tekstvergelijking).
 */
final class PhotoContinueAnywayAttention
{
    public const CODE_PREFIX = 'photo_continue_anyway__';

    /** @var array<string, string> question_key → decision area key */
    private const QUESTION_AREA = [
        'fusebox_photo' => 'power',
        'fusebox_photo_extra' => 'power',
        'outdoor_location_photos' => 'placement',
        'around_house_photos' => 'placement',
        'drain_photo' => 'condensate',
        'pipe_route_photos' => 'refrigerant',
        'indoor_unit_position_photo' => 'placement',
    ];

    public static function isContinueAnywayCode(string $code): bool
    {
        return str_starts_with($code, self::CODE_PREFIX);
    }

    public static function buildCode(string $questionKey, ?string $sectionInstanceKey): string
    {
        $instance = $sectionInstanceKey === null || $sectionInstanceKey === ''
            ? 'site'
            : $sectionInstanceKey;

        return self::CODE_PREFIX.$questionKey.'__'.$instance;
    }

    /**
     * @return array{question_key: string, section_instance_key: string|null}|null
     */
    public static function parseCode(string $code): ?array
    {
        if (! self::isContinueAnywayCode($code)) {
            return null;
        }

        $rest = substr($code, strlen(self::CODE_PREFIX));
        $parts = explode('__', $rest, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            return null;
        }

        $instance = $parts[1] === 'site' ? null : $parts[1];

        return [
            'question_key' => $parts[0],
            'section_instance_key' => $instance,
        ];
    }

    public static function galleryAnchor(string $questionKey, ?string $sectionInstanceKey): string
    {
        if (is_string($sectionInstanceKey) && str_starts_with($sectionInstanceKey, 'room-')) {
            return 'gallery-'.$sectionInstanceKey;
        }

        $area = self::QUESTION_AREA[$questionKey] ?? 'photos';

        return 'gallery-'.$area.'-'.$questionKey;
    }

    /**
     * Link onder een Actueel-fotomelding: anker + meervoud.
     *
     * @return array{anchor: string, count: int, link_label: string}|null
     */
    public static function linkForPoint(Intake $intake, string $code): ?array
    {
        $parsed = self::parseCode($code);
        if ($parsed === null) {
            return null;
        }

        $intake->loadMissing(['uploads']);
        $supersessions = app(UploadSupersessionResolver::class)->resolve($intake);

        $rejectedOrPending = 0;
        $total = 0;
        foreach ($intake->uploads as $upload) {
            if ($upload->question_key !== $parsed['question_key']
                || $upload->section_instance_key !== $parsed['section_instance_key']
                || $upload->intake_follow_up_item_id !== null) {
                continue;
            }

            $info = $supersessions[(int) $upload->id] ?? null;
            if ($info !== null && $info['superseded'] === true) {
                continue;
            }

            $total++;
            $status = $upload->contentAssessment()?->status();
            if ($status === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                || $status === PhotoContentAssessment::STATUS_NEEDS_CLEARER
                || $status === PhotoContentAssessment::STATUS_NOT_ASSESSED) {
                $rejectedOrPending++;
            }
        }

        $count = $rejectedOrPending > 0 ? $rejectedOrPending : max(1, $total);

        return [
            'anchor' => self::galleryAnchor($parsed['question_key'], $parsed['section_instance_key']),
            'count' => $count,
            'link_label' => $count === 1 ? 'Bekijk foto' : 'Bekijk foto’s',
        ];
    }

    /**
     * Pleklabel: ruimtenaam (zelfde bron als overzicht) of beslisgebied.
     */
    public static function placeLabel(
        Intake $intake,
        IntakeTemplateVersion $version,
        string $questionKey,
        ?string $sectionInstanceKey,
    ): string {
        if (is_string($sectionInstanceKey) && str_starts_with($sectionInstanceKey, 'room-')) {
            return self::roomPlaceLabel($intake, $sectionInstanceKey);
        }

        $areaKey = self::QUESTION_AREA[$questionKey] ?? null;
        if (is_string($areaKey) && DecisionReadinessService::hasArea($areaKey)) {
            return DecisionReadinessService::areaLabel($areaKey);
        }

        $question = self::findQuestion($version, $questionKey);
        if ($question instanceof IntakeQuestion && trim((string) $question->label) !== '') {
            return trim((string) $question->label);
        }

        return 'Opname';
    }

    public static function roomPlaceLabel(Intake $intake, string $sectionInstanceKey): string
    {
        $intake->loadMissing(['aircoRooms', 'answers']);

        /** @var Collection<int, AircoRoom> $rooms */
        $rooms = $intake->aircoRooms
            ->filter(static fn (AircoRoom $room): bool => str_starts_with($room->key, 'room-'))
            ->values();

        $room = $rooms->firstWhere('key', $sectionInstanceKey);
        $name = $room instanceof AircoRoom ? trim($room->name) : '';

        if ($name === '') {
            $suffix = Str::afterLast($sectionInstanceKey, '-');

            return 'Ruimte '.(is_numeric($suffix) ? $suffix : $sectionInstanceKey);
        }

        $normalized = mb_strtolower($name);
        $duplicateCount = $rooms->filter(
            static fn (AircoRoom $other): bool => mb_strtolower(trim($other->name)) === $normalized,
        )->count();

        if ($duplicateCount > 1) {
            $floor = self::floorLabelFromAnswers($intake, $sectionInstanceKey);
            if ($floor !== null && ! str_contains($normalized, mb_strtolower($floor))) {
                return $name.', '.$floor;
            }
        }

        return $name;
    }

    /**
     * @param  list<IntakeUpload>  $uploadsAtPlace  niet-vervangen uploads bij deze vraag+plek
     * @return array{code: string, label: string, gallery_anchor: string, photo_link_count: int}
     */
    public static function buildPoint(
        Intake $intake,
        IntakeTemplateVersion $version,
        string $questionKey,
        ?string $sectionInstanceKey,
        array $uploadsAtPlace,
    ): array {
        $rejected = 0;
        $notAssessed = 0;
        $total = count($uploadsAtPlace);

        foreach ($uploadsAtPlace as $upload) {
            $status = $upload->contentAssessment()?->status();
            if ($status === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                || $status === PhotoContentAssessment::STATUS_NEEDS_CLEARER) {
                $rejected++;
            } elseif ($status === PhotoContentAssessment::STATUS_NOT_ASSESSED) {
                $notAssessed++;
            }
        }

        $place = self::placeLabel($intake, $version, $questionKey, $sectionInstanceKey);
        $question = self::findQuestion($version, $questionKey);
        $questionLabel = $question instanceof IntakeQuestion && trim((string) $question->label) !== ''
            ? trim((string) $question->label)
            : $questionKey;

        $sentences = self::buildSentences($rejected, $notAssessed, $total);
        $label = $place.' · '.$questionLabel.': '.$sentences;

        return [
            'code' => self::buildCode($questionKey, $sectionInstanceKey),
            'label' => $label,
            'gallery_anchor' => self::galleryAnchor($questionKey, $sectionInstanceKey),
            'photo_link_count' => $rejected + $notAssessed > 0
                ? $rejected + $notAssessed
                : $total,
        ];
    }

    private static function buildSentences(int $rejected, int $notAssessed, int $total): string
    {
        $parts = [];

        if ($notAssessed > 0 && $rejected === 0) {
            $parts[] = $notAssessed === 1
                ? 'De AI kon 1 foto niet beoordelen.'
                : "De AI kon {$notAssessed} foto’s niet beoordelen.";
        } else {
            if ($rejected > 0) {
                if ($rejected === $total) {
                    $parts[] = $rejected === 1
                        ? 'de AI keurde 1 foto af.'
                        : "de AI keurde {$rejected} foto’s af.";
                } else {
                    $parts[] = $rejected === 1
                        ? "de AI keurde 1 van de {$total} foto’s af."
                        : "de AI keurde {$rejected} van de {$total} foto’s af.";
                }
            }

            if ($notAssessed > 0) {
                $parts[] = $notAssessed === 1
                    ? 'De AI kon 1 foto niet beoordelen.'
                    : "De AI kon {$notAssessed} foto’s niet beoordelen.";
            }
        }

        $parts[] = 'De klant koos ‘Toch doorgaan’.';

        $text = implode(' ', $parts);

        // Eerste zin mag met kleine letter beginnen na de dubbele punt in het label,
        // behalve wanneer die met "De AI" begint.
        return $text;
    }

    private static function floorLabelFromAnswers(Intake $intake, string $instanceKey): ?string
    {
        $answer = $intake->answers->first(
            static fn (IntakeAnswer $row): bool => $row->section_instance_key === $instanceKey
                && $row->question_key === 'floor_level',
        );
        $value = $answer?->value;
        if (! is_array($value)) {
            return null;
        }

        $raw = $value['value'] ?? $value['text'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return match (trim($raw)) {
            'basement' => 'kelder',
            'ground' => 'begane grond',
            '1' => '1e verdieping',
            '2' => '2e verdieping',
            '3_plus' => '3e verdieping of hoger',
            'attic' => 'zolder',
            default => null,
        };
    }

    private static function findQuestion(IntakeTemplateVersion $version, string $questionKey): ?IntakeQuestion
    {
        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return $question;
                }
            }
        }

        return null;
    }
}
