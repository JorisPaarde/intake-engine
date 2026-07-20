<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DocumentUploadNormalizer;
use App\Domains\Intake\Services\NormalizedPhotoUpload;
use App\Domains\Intake\Services\PhotoUploadNormalizer;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StoreFollowUpUpload
{
    public function __construct(
        private readonly PhotoUploadNormalizer $photoUploadNormalizer,
        private readonly DocumentUploadNormalizer $documentUploadNormalizer,
    ) {}

    public function handle(Intake $intake, IntakeFollowUpItem $item, UploadedFile $file): IntakeUpload
    {
        $item->loadMissing('round');

        if ($item->round->intake_id !== $intake->id
            || $item->round->status !== FollowUpRoundStatus::Open
            || ! in_array($item->type, [FollowUpItemType::Photo, FollowUpItemType::Document], true)
            || $intake->status !== IntakeStatus::AwaitingCustomer) {
            throw ValidationException::withMessages([
                'upload' => 'Deze uploadopdracht is niet meer beschikbaar.',
            ]);
        }

        $isPhoto = $item->type === FollowUpItemType::Photo;
        $maxFiles = $isPhoto
            ? (int) config('intake.follow_up.max_photos_per_item', 5)
            : (int) config('intake.follow_up.max_documents_per_item', 3);
        $maxKilobytes = (int) config('intake.uploads.max_kilobytes', 5120);
        $existingCount = $item->uploads()->count();
        $fileLabel = $isPhoto ? 'foto' : 'document';

        if ($existingCount >= $maxFiles) {
            throw ValidationException::withMessages([
                'upload' => "Je kunt maximaal {$maxFiles} {$fileLabel}s bij deze opdracht uploaden.",
            ]);
        }

        if ($file->getSize() !== false && $file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'upload' => 'Dit bestand is te groot. Maximaal '.($maxKilobytes / 1024).' MB.',
            ]);
        }

        $normalized = $isPhoto
            ? $this->photoUploadNormalizer->normalize($file)
            : $this->documentUploadNormalizer->normalize($file);

        try {
            $disk = (string) config('filesystems.media', 'local');
            $path = 'intakes/'.$intake->uuid.'/follow-up/'.$item->round->round_number.'/'.$item->id.'/'.Str::ulid()->toBase32().'.'.$normalized->extension;

            if (! Storage::disk($disk)->put($path, File::get($normalized->absolutePath))) {
                throw ValidationException::withMessages([
                    'upload' => 'Upload mislukt. Probeer het opnieuw.',
                ]);
            }

            return DB::transaction(function () use ($intake, $item, $disk, $path, $normalized, $existingCount): IntakeUpload {
                $upload = IntakeUpload::query()->create([
                    'intake_id' => $intake->id,
                    'question_key' => 'follow_up_'.$item->id,
                    'section_instance_key' => null,
                    'intake_follow_up_item_id' => $item->id,
                    'disk' => $disk,
                    'path' => $path,
                    'original_filename' => $normalized->originalFilename,
                    'mime_type' => $normalized->mime,
                    'size_bytes' => $normalized->sizeBytes,
                    'checksum' => $normalized->checksum,
                    'sort_order' => $existingCount + 1,
                ]);

                $item->update(['answered_at' => now()]);

                IntakeActivityEvent::query()->create([
                    'intake_id' => $intake->id,
                    'actor_type' => 'customer',
                    'actor_id' => null,
                    'event' => 'follow_up_upload_stored',
                    'properties' => [
                        'round_number' => $item->round->round_number,
                        'item_id' => $item->id,
                        'item_type' => $item->type->value,
                        'upload_id' => $upload->id,
                    ],
                    'created_at' => now(),
                ]);

                return $upload;
            });
        } finally {
            if ($normalized instanceof NormalizedPhotoUpload) {
                foreach ($normalized->cleanupPaths as $cleanupPath) {
                    @unlink($cleanupPath);
                }
            }
        }
    }
}
