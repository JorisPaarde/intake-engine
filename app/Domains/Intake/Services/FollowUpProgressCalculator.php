<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\FollowUpItemType;
use App\Enums\PhotoUsabilityVerdict;
use Illuminate\Support\Collection;

/**
 * Voortgang van een gerichte klantaanvulling op basis van afgeronde items,
 * niet op de huidige stappositie (klanttest P2).
 *
 * Foto's tellen pas mee als ze bruikbaar beoordeeld zijn én (waar van toepassing)
 * een content_assessment hebben zonder onopgeloste wrong_subject. Een correcte
 * vervangfoto lost het item op, ook als een eerdere verkeerde foto nog hangt
 * (historie blijft in activity log / oude upload).
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
     * @return 'empty'|'received'|'assessed'|'unusable'|'mismatch'|'complete'
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

        if (! $allUsable) {
            return 'unusable';
        }

        // Wacht tot elke foto een content_assessment heeft (queue-job schrijft die altijd).
        $pendingContent = $item->uploads->contains(
            static fn (IntakeUpload $upload): bool => $upload->contentAssessment() === null,
        );

        if ($pendingContent) {
            return 'received';
        }

        if ($this->requiresSubjectAssessment($item)) {
            // Correcte (of geaccepteerde) foto lost het item op — oude wrong_subject blijft historie.
            if ($this->hasSolvingUpload($item->uploads)) {
                return 'assessed';
            }

            if ($this->hasUnresolvedWrongSubject($item->uploads)) {
                return 'mismatch';
            }

            // Alleen not_assessed (soft-fail): klant mag door.
            return 'assessed';
        }

        return 'assessed';
    }

    private function requiresSubjectAssessment(IntakeFollowUpItem $item): bool
    {
        $area = ContributionTask::query()
            ->where('intake_follow_up_item_id', $item->id)
            ->value('decision_area_key');

        if (! is_string($area) || $area === '') {
            return false;
        }

        return PhotoSubject::acceptedSubjectsForDecisionArea($area) !== null;
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    private function hasSolvingUpload(Collection $uploads): bool
    {
        foreach ($uploads as $upload) {
            $assessment = $upload->contentAssessment();

            if ($assessment instanceof PhotoContentAssessment && $assessment->solvesContent()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    private function hasUnresolvedWrongSubject(Collection $uploads): bool
    {
        foreach ($uploads as $upload) {
            $assessment = $upload->contentAssessment();

            if ($assessment instanceof PhotoContentAssessment
                && $assessment->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                && ! $assessment->customerAcceptedMismatch()) {
                return true;
            }
        }

        return false;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'received' => 'Ontvangen, wordt beoordeeld',
            'assessed' => 'Beoordeeld',
            'unusable' => 'Nieuwe foto nodig',
            'mismatch' => 'Nog te vervangen',
            'complete' => 'Compleet',
            default => 'Nog te doen',
        };
    }
}
