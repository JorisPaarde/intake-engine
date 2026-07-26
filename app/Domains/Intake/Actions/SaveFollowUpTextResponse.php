<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveFollowUpTextResponse
{
    public function handle(Intake $intake, IntakeFollowUpItem $item, ?string $response): void
    {
        $item->loadMissing('round');

        if ($item->round->intake_id !== $intake->id
            || $item->round->status !== FollowUpRoundStatus::Open
            || $item->type !== FollowUpItemType::Text
            || $intake->status !== IntakeStatus::AwaitingCustomer) {
            throw ValidationException::withMessages([
                'follow_up' => 'Deze aanvullende vraag is niet meer beschikbaar.',
            ]);
        }

        $response = trim((string) $response);

        DB::transaction(function () use ($intake, $item, $response): void {
            $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $lockedItem = IntakeFollowUpItem::query()->with('round')->lockForUpdate()->findOrFail($item->id);

            if ($lockedItem->round->intake_id !== $intake->id
                || $lockedItem->round->status !== FollowUpRoundStatus::Open
                || $lockedItem->type !== FollowUpItemType::Text
                || $lockedIntake->status !== IntakeStatus::AwaitingCustomer) {
                throw ValidationException::withMessages([
                    'follow_up' => 'Deze aanvullende vraag is niet meer beschikbaar.',
                ]);
            }

            $lockedItem->update([
                'response_text' => $response !== '' ? $response : null,
                'answered_at' => $response !== '' ? now() : null,
            ]);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'customer',
                'actor_id' => null,
                'event' => 'follow_up_text_saved',
                'properties' => [
                    'round_number' => $lockedItem->round->round_number,
                    'item_id' => $lockedItem->id,
                    'has_response' => $response !== '',
                ],
                'created_at' => now(),
            ]);
        }, 3);
    }
}
