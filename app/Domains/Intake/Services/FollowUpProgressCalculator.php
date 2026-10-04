<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\PhotoOverridePolicy;
use App\Enums\FollowUpItemType;
use App\Enums\PhotoAssessmentStatus;
use Illuminate\Support\Collection;

/**
 * Voortgang van een gerichte klantaanvulling op basis van afgeronde items,
 * niet op de huidige stappositie (klanttest P2).
 *
 * Foto's tellen pas mee als assessment_status terminaal is zonder onopgeloste
 * override (verkeerde categorie, lage resolutie, not_assessed, …).
 * Tijdens queue-beoordeling blijft het item op "Ontvangen".
 */
final class FollowUpProgressCalculator
{
    /**
     * @param  Collection<int, IntakeFollowUpItem>  $items
     * @param  array<int|string, mixed>  $liveResponses  Livewire-antwoorden die nog niet geperisteerd zijn
     * @return array{
     *     percent: int,
     *     completed: int,
     *     total: int,
     *     item_statuses: array<int, array{status: string, label: string}>
     * }
     */
    public function calculate(Collection $items, array $liveResponses = []): array
    {
        $total = $items->count();
        $completed = 0;
        $itemStatuses = [];

        foreach ($items as $item) {
            $live = $liveResponses[$item->id] ?? $liveResponses[(string) $item->id] ?? null;
            $status = $this->itemStatus($item, is_string($live) ? $live : null);
            $itemStatuses[$item->id] = [
                'status' => $status,
                'label' => $this->statusLabel($status),
            ];

            // needs_review = expliciete override; telt mee zodat versturen kan,
            // maar het label maakt duidelijk dat de installateur nog moet kijken.
            if (in_array($status, ['complete', 'assessed', 'needs_review'], true)) {
                $completed++;
            }
        }

        $percent = $total === 0
            ? 100
            : (int) round(($completed / $total) * 100);

        return [
            'percent' => max(0, min(100, $percent)),
            'completed' => $completed,
            'total' => $total,
            'item_statuses' => $itemStatuses,
        ];
    }

    /**
     * @return 'empty'|'received'|'assessed'|'unusable'|'mismatch'|'needs_review'|'complete'
     */
    public function itemStatus(IntakeFollowUpItem $item, ?string $liveResponse = null): string
    {
        if ($item->type === FollowUpItemType::Text || $item->type === FollowUpItemType::Choice) {
            $response = trim((string) ($liveResponse ?? $item->response_text ?? ''));

            return $response === '' ? 'empty' : 'complete';
        }

        $item->loadMissing('uploads');

        if ($item->uploads->isEmpty()) {
            return 'empty';
        }

        if ($item->type === FollowUpItemType::Document) {
            return 'assessed';
        }

        $allTerminal = $item->uploads->every(
            static fn (IntakeUpload $upload): bool => $upload->assessment_status instanceof PhotoAssessmentStatus
                && $upload->assessment_status->isTerminal(),
        );

        if (! $allTerminal) {
            return 'received';
        }

        $unresolved = PhotoOverridePolicy::unresolvedOverride($item->uploads);
        if ($unresolved instanceof IntakeUpload) {
            $assessment = $unresolved->contentAssessment();

            if ($assessment?->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT) {
                return 'mismatch';
            }

            return 'unusable';
        }

        if (PhotoOverridePolicy::hasAcceptedOverride($item->uploads)) {
            return 'needs_review';
        }

        return 'assessed';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'received' => 'Ontvangen, wordt beoordeeld',
            'assessed' => 'Beoordeeld',
            'unusable' => 'Nieuwe foto nodig',
            'mismatch' => 'Nog te vervangen',
            'needs_review' => 'Verstuurd — installateur beoordeelt nog',
            'complete' => 'Compleet',
            default => 'Nog te doen',
        };
    }
}
