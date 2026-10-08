<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Jobs\DeleteStoredMediaJob;
use App\Domains\Intake\Models\DossierEvidenceLink;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Removing a follow-up upload happens in two steps (BL-147, UX #16.4):
 * softRemove() hides it right away (soft delete, file and assessment stay),
 * restore() undoes that, and finalize() does the real wipe (evidence links,
 * stored media, activity event, purged_at) after the undo window or on navigation.
 * A soft-deleted upload is invisible to every query (report, AI, workspace).
 * Closing the tab within the undo window leaves it in that “bin”; it is purged on
 * the next visit of the customer link (purgePendingFor), on “Aanvulling versturen”
 * (CompleteFollowUpRound) or by the hourly cleanup (purgeRemovedBefore).
 * handle() keeps the immediate delete for documents and “Vervang foto”.
 */
final class DeleteFollowUpUpload
{
    public function handle(Intake $intake, IntakeFollowUpItem $item, IntakeUpload $upload): void
    {
        $this->softRemove($intake, $item, $upload);
        $this->finalize($intake, $upload->id);
    }

    /**
     * @return Carbon|null The item's answered_at before removal (for restore()).
     */
    public function softRemove(Intake $intake, IntakeFollowUpItem $item, IntakeUpload $upload): ?Carbon
    {
        $this->guard($intake, $item, $upload);

        return DB::transaction(function () use ($intake, $item, $upload): ?Carbon {
            [$lockedItem, $lockedUpload] = $this->lock($intake, $item, $upload->id);

            $previousAnsweredAt = $lockedItem->answered_at;
            $lockedUpload->delete();

            if (! $lockedItem->uploads()->exists()) {
                $lockedItem->update(['answered_at' => null]);
            }

            return $previousAnsweredAt;
        }, 3);
    }

    /**
     * Undo a softRemove(): the photo and its assessment come back unchanged.
     */
    public function restore(Intake $intake, IntakeFollowUpItem $item, int $uploadId, ?Carbon $previousAnsweredAt): void
    {
        $upload = IntakeUpload::withTrashed()->whereKey($uploadId)->firstOrFail();
        $this->guard($intake, $item, $upload);

        DB::transaction(function () use ($intake, $item, $uploadId, $previousAnsweredAt): void {
            [$lockedItem, $lockedUpload] = $this->lock($intake, $item, $uploadId);

            // Already purged (other tab, next visit, cleanup): the file is gone.
            if ($lockedUpload->purged_at !== null) {
                throw ValidationException::withMessages([
                    'upload' => 'Ongedaan maken mislukt.',
                ]);
            }

            if ($lockedUpload->trashed()) {
                $lockedUpload->restore();
            }

            if ($lockedItem->answered_at === null) {
                $lockedItem->update(['answered_at' => $previousAnsweredAt ?? now()]);
            }
        }, 3);
    }

    /**
     * Real removal of a soft-removed upload. No-op when it was restored or purged meanwhile.
     */
    public function finalize(Intake $intake, int $uploadId): void
    {
        $this->purge($uploadId, $intake->id, 'customer');
    }

    /**
     * Purge every follow-up upload of this intake that is still in the bin
     * (next visit of the customer link, “Aanvulling versturen”).
     */
    public function purgePendingFor(Intake $intake): int
    {
        $ids = IntakeUpload::onlyTrashed()
            ->where('intake_id', $intake->id)
            ->whereNotNull('intake_follow_up_item_id')
            ->whereNull('purged_at')
            ->pluck('id');

        foreach ($ids as $id) {
            $this->purge((int) $id, $intake->id, 'customer');
        }

        return $ids->count();
    }

    /**
     * Hourly cleanup: purge follow-up uploads that sat in the bin since before $cutoff.
     */
    public function purgeRemovedBefore(CarbonInterface $cutoff): int
    {
        $purged = 0;

        IntakeUpload::onlyTrashed()
            ->whereNotNull('intake_follow_up_item_id')
            ->whereNull('purged_at')
            ->where('deleted_at', '<=', $cutoff)
            ->select(['id'])
            ->chunkById(100, function ($uploads) use (&$purged): void {
                foreach ($uploads as $upload) {
                    $this->purge((int) $upload->id, null, 'system');
                    $purged++;
                }
            });

        return $purged;
    }

    private function purge(int $uploadId, ?int $intakeId, string $actorType): void
    {
        $result = DB::transaction(function () use ($uploadId, $intakeId, $actorType): ?array {
            $lockedUpload = IntakeUpload::withTrashed()->whereKey($uploadId)->lockForUpdate()->first();

            if ($lockedUpload === null
                || ! $lockedUpload->trashed()
                || $lockedUpload->purged_at !== null
                || ($intakeId !== null && $lockedUpload->intake_id !== $intakeId)) {
                return null;
            }

            $lockedItem = IntakeFollowUpItem::query()->with('round')->find($lockedUpload->intake_follow_up_item_id);

            DossierEvidenceLink::query()
                ->where('intake_id', $lockedUpload->intake_id)
                ->where('evidence_type', 'intake_upload')
                ->where('evidence_id', $lockedUpload->id)
                ->delete();

            $lockedUpload->forceFill(['purged_at' => now()])->saveQuietly();

            IntakeActivityEvent::query()->create([
                'intake_id' => $lockedUpload->intake_id,
                'actor_type' => $actorType,
                'actor_id' => null,
                'event' => 'follow_up_upload_deleted',
                'properties' => [
                    'round_number' => $lockedItem?->round?->round_number,
                    'item_id' => $lockedItem?->id,
                    'item_type' => $lockedItem?->type->value,
                    'upload_id' => $lockedUpload->id,
                ],
                'created_at' => now(),
            ]);

            return [$lockedUpload->disk, $lockedUpload->path, $lockedUpload->analysis_path];
        }, 3);

        if ($result === null) {
            return;
        }

        [$disk, $path, $analysisPath] = $result;
        $this->deleteStoredMedia($disk, $path);

        if (is_string($analysisPath) && $analysisPath !== '') {
            $this->deleteStoredMedia($disk, $analysisPath);
        }
    }

    private function guard(Intake $intake, IntakeFollowUpItem $item, IntakeUpload $upload): void
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
    }

    /**
     * @return array{0: IntakeFollowUpItem, 1: IntakeUpload}
     */
    private function lock(Intake $intake, IntakeFollowUpItem $item, int $uploadId): array
    {
        $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
        $lockedItem = IntakeFollowUpItem::query()->with('round')->lockForUpdate()->findOrFail($item->id);
        $lockedUpload = IntakeUpload::withTrashed()->whereKey($uploadId)->lockForUpdate()->firstOrFail();

        if ($lockedItem->round->intake_id !== $lockedIntake->id
            || $lockedItem->round->status !== FollowUpRoundStatus::Open
            || $lockedIntake->status !== IntakeStatus::AwaitingCustomer
            || $lockedUpload->intake_id !== $lockedIntake->id
            || $lockedUpload->intake_follow_up_item_id !== $lockedItem->id) {
            throw ValidationException::withMessages([
                'upload' => 'Dit bestand kan niet worden verwijderd.',
            ]);
        }

        return [$lockedItem, $lockedUpload];
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
