<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Enums\FollowUpItemType;
use App\Enums\PhotoUsabilityVerdict;
use Illuminate\Support\Collection;

/**
 * Voortgang van een gerichte klantaanvulling op basis van afgeronde items,
 * niet op de huidige stappositie (klanttest P2).
 *
 * Foto's tellen pas mee als ze bruikbaar beoordeeld zijn; tijdens beoordeling
 * of bij onbruikbare foto's blijft het item open.
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

            if (in_array($status, ['complete', 'assessed'], true)) {
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
     * @return 'empty'|'received'|'assessed'|'unusable'|'complete'
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

        $allAssessed = $item->uploads->every(
            static fn ($upload): bool => $upload->usability_verdict instanceof PhotoUsabilityVerdict,
        );

        if (! $allAssessed) {
            return 'received';
        }

        $allUsable = $item->uploads->every(
            static fn ($upload): bool => $upload->usability_verdict instanceof PhotoUsabilityVerdict
                && $upload->usability_verdict->isUsable(),
        );

        return $allUsable ? 'assessed' : 'unusable';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'received' => 'Ontvangen, wordt beoordeeld',
            'assessed' => 'Beoordeeld',
            'unusable' => 'Nieuwe foto nodig',
            'complete' => 'Compleet',
            default => 'Nog te doen',
        };
    }
}
