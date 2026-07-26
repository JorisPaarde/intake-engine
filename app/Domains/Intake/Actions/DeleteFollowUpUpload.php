<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Jobs\DeleteStoredMediaJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

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

        [$disk, $path] = DB::transaction(function () use ($intake, $item, $upload): array {
            $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $lockedItem = IntakeFollowUpItem::query()->with('round')->lockForUpdate()->findOrFail($item->id);
            $lockedUpload = IntakeUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();

            if ($lockedItem->round->intake_id !== $lockedIntake->id
                || $lockedItem->round->status !== FollowUpRoundStatus::Open
                || $lockedIntake->status !== IntakeStatus::AwaitingCustomer
                || $lockedUpload->intake_id !== $lockedIntake->id
                || $lockedUpload->intake_follow_up_item_id !== $lockedItem->id) {
                throw ValidationException::withMessages([
                    'upload' => 'Dit bestand kan niet worden verwijderd.',
                ]);
            }

            $disk = $lockedUpload->disk;
            $path = $lockedUpload->path;
            $uploadId = $lockedUpload->id;
            $lockedUpload->delete();

            if (! $lockedItem->uploads()->exists()) {
                $lockedItem->update(['answered_at' => null]);
            }

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'customer',
                'actor_id' => null,
                'event' => 'follow_up_upload_deleted',
                'properties' => [
                    'round_number' => $lockedItem->round->round_number,
                    'item_id' => $lockedItem->id,
                    'item_type' => $lockedItem->type->value,
                    'upload_id' => $uploadId,
                ],
                'created_at' => now(),
            ]);

            return [$disk, $path];
        }, 3);

        $this->deleteStoredMedia($disk, $path);
    }

    private function deleteStoredMedia(string $disk, string $path): void
    {
        try {
            if (Storage::disk($disk)->delete($path)) {
                return;
            }
        } catch (Throwable) {
            // Retry asynchronously after the database mutation has committed.
        }

        DeleteStoredMediaJob::dispatch($disk, $path);
    }
}
