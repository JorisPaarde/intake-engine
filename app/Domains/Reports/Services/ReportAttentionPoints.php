<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Support\PhotoContinueAnywayAttention;

/**
 * Report-only presentation of aandachtspunten: collapse duplicate Toch-doorgaan
 * cards (same fusebox/topic or identical label) so AI rebuild / legacy codes
 * do not repeat the meterkast-issue 5×.
 */
final class ReportAttentionPoints
{
    /**
     * @param  list<array{code?: string, label?: string}>  $points
     * @return list<array{code: string, label: string}>
     */
    public function uniqueForReport(Intake $intake, array $points): array
    {
        if ($points === []) {
            return [];
        }

        /** @var array<string, true> $seenGroups */
        $seenGroups = [];
        /** @var array<string, true> $seenLabels */
        $seenLabels = [];
        $unique = [];

        foreach ($points as $point) {
            $code = is_string($point['code'] ?? null) ? (string) $point['code'] : '';
            $label = is_string($point['label'] ?? null) ? trim((string) $point['label']) : '';
            if ($code === '' || $label === '') {
                continue;
            }

            if (isset($seenLabels[$label])) {
                continue;
            }

            // Topic-groep alleen als de intake template kan laden (niet in kale unit-doubles).
            if (PhotoContinueAnywayAttention::isContinueAnywayCode($code)
                && $intake->exists) {
                $group = PhotoContinueAnywayAttention::displayGroupKey($intake, $code);
                if (is_string($group) && $group !== '') {
                    if (isset($seenGroups[$group])) {
                        continue;
                    }
                    $seenGroups[$group] = true;
                }
            }

            $seenLabels[$label] = true;
            $unique[] = [
                'code' => $code,
                'label' => $label,
            ];
        }

        return $unique;
    }
}
