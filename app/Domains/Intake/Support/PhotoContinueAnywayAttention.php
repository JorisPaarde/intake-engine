<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DecisionReadinessService;
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

    /**
     * Stabiel anker voor galerijgroep én Actueel-link: sectie + instantie, niet de vraag.
     */
    public static function galleryAnchor(?string $sectionKey, ?string $sectionInstanceKey): string
    {
        if (is_string($sectionInstanceKey) && str_starts_with($sectionInstanceKey, 'room-')) {
            return 'gallery-'.$sectionInstanceKey;
        }

        $section = is_string($sectionKey) && $sectionKey !== '' ? $sectionKey : 'photos';
        if ($sectionInstanceKey === null || $sectionInstanceKey === '') {
            return 'gallery-'.$section;
        }

        return 'gallery-'.$section.'-'.$sectionInstanceKey;
    }

    public static function sectionKeyForQuestion(IntakeTemplateVersion $version, string $questionKey): ?string
    {
        $section = self::findSectionForQuestion($version, $questionKey);

        return $section instanceof IntakeSection ? $section->key : null;
    }

    /**
     * @param  list<IntakeUpload>  $uploads
     * @return array{total: int, rejected: int, not_assessed: int}
     */
    public static function countStatuses(array $uploads): array
    {
        $rejected = 0;
        $notAssessed = 0;

        foreach ($uploads as $upload) {
            $status = $upload->contentAssessment()?->status();
            if ($status === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                || $status === PhotoContentAssessment::STATUS_NEEDS_CLEARER) {
                $rejected++;
            } elseif ($status === PhotoContentAssessment::STATUS_NOT_ASSESSED) {
                $notAssessed++;
            }
        }

        return [
            'total' => count($uploads),
            'rejected' => $rejected,
            'not_assessed' => $notAssessed,
        ];
    }

    /**
     * Link onder een Actueel-fotomelding: anker + meervoud.
     * Geen link als er geen huidige (niet-vervangen) uploads meer zijn.
     *
     * @return array{anchor: string, count: int, link_label: string}|null
     */
    public static function linkForPoint(Intake $intake, string $code): ?array
    {
        $parsed = self::parseCode($code);
        if ($parsed === null) {
            return null;
        }

        $intake->loadMissing(['uploads', 'templateVersion.sections.questions']);
        $supersessions = app(UploadSupersessionResolver::class)->resolve($intake);

        /** @var list<IntakeUpload> $uploads */
        $uploads = [];
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

            $uploads[] = $upload;
        }

        $counts = self::countStatuses($uploads);
        if ($counts['total'] === 0) {
            return null;
        }

        $linkCount = $counts['rejected'] + $counts['not_assessed'];
        if ($linkCount === 0) {
            $linkCount = $counts['total'];
        }

        $sectionKey = null;
        $version = $intake->templateVersion;
        if ($version !== null) {
            $sectionKey = self::sectionKeyForQuestion($version, $parsed['question_key']);
        }

        return [
            'anchor' => self::galleryAnchor($sectionKey, $parsed['section_instance_key']),
            'count' => $linkCount,
            'link_label' => $linkCount === 1 ? 'Bekijk foto' : 'Bekijk foto’s',
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

    /**
     * Eén bron: opgeslagen AircoRoom.name (DossierManager plakt verdieping al).
     */
    public static function roomPlaceLabel(Intake $intake, string $sectionInstanceKey): string
    {
        $intake->loadMissing('aircoRooms');

        $room = $intake->aircoRooms->firstWhere('key', $sectionInstanceKey);
        $name = $room instanceof AircoRoom ? trim($room->name) : '';

        if ($name !== '') {
            return $name;
        }

        $suffix = Str::afterLast($sectionInstanceKey, '-');

        return 'Ruimte '.(is_numeric($suffix) ? $suffix : $sectionInstanceKey);
    }

    /**
     * @param  list<IntakeUpload>  $uploadsAtPlace  niet-vervangen uploads bij deze vraag+plek
     * @return array{code: string, label: string}
     */
    public static function buildPoint(
        Intake $intake,
        IntakeTemplateVersion $version,
        string $questionKey,
        ?string $sectionInstanceKey,
        array $uploadsAtPlace,
    ): array {
        $counts = self::countStatuses($uploadsAtPlace);

        $place = self::placeLabel($intake, $version, $questionKey, $sectionInstanceKey);
        $question = self::findQuestion($version, $questionKey);
        $questionLabel = $question instanceof IntakeQuestion && trim((string) $question->label) !== ''
            ? trim((string) $question->label)
            : $questionKey;

        $sentences = self::buildSentences(
            $counts['rejected'],
            $counts['not_assessed'],
            $counts['total'],
        );
        $label = $place.' · '.$questionLabel.': '.$sentences;

        return [
            'code' => self::buildCode($questionKey, $sectionInstanceKey),
            'label' => $label,
        ];
    }

    private static function buildSentences(int $rejected, int $notAssessed, int $total): string
    {
        $parts = [];

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

        $parts[] = 'De klant koos ‘Toch doorgaan’.';

        return implode(' ', $parts);
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

    private static function findSectionForQuestion(IntakeTemplateVersion $version, string $questionKey): ?IntakeSection
    {
        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return $section;
                }
            }
        }

        return null;
    }
}
