<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class DeleteFollowUpUpload
{
    public function handle(Intake $intake, IntakeFollowUpItem $item, IntakeUpload $upload): void
    {
        $item->loadMissing('round');

        if ($item->round->intake_id !== $intake->id
            || $item->round->status !== FollowUpRoundStatus::Open
            || $intake->status !== IntakeStatus::AwaitingCustomer
            || $upload->intake_id !== $intake->id
            || $upload->intake_follow_up_item_id !== $item->id) {
            throw ValidationException::withMessages([
                'upload' => 'Dit bestand kan niet worden verwijderd.',
            ]);
        }

        DB::transaction(function () use ($intake, $item, $upload): void {
            $disk = $upload->disk;
            $path = $upload->path;
            $uploadId = $upload->id;
            $upload->delete();

            Storage::disk($disk)->delete($path);

            if (! $item->uploads()->exists()) {
                $item->update(['answered_at' => null]);
            }

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'customer',
                'actor_id' => null,
                'event' => 'follow_up_upload_deleted',
                'properties' => [
                    'round_number' => $item->round->round_number,
                    'item_id' => $item->id,
                    'item_type' => $item->type->value,
                    'upload_id' => $uploadId,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
