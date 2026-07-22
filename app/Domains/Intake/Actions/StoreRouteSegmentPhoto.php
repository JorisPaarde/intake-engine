<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\PhotoUploadNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Slaat een routefoto op voor de begeleide leidingroute. Anders dan `StoreIntakeUpload`
 * hangt deze niet aan een template-fotovraag — routefoto's zijn eigen segmenten, geen
 * antwoorden. Hergebruikt wél dezelfde normalisatie (HEIC/oriëntatie/formaat).
 */
final class StoreRouteSegmentPhoto
{
    public const QUESTION_KEY = 'pipe_route_guided';

    public function __construct(
        private readonly PhotoUploadNormalizer $photoUploadNormalizer,
    ) {}

    public function handle(Intake $intake, UploadedFile $file): IntakeUpload
    {
        $maxKilobytes = (int) config('intake.uploads.max_kilobytes', 5120);

        if ($file->getSize() !== false && $file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'photo' => 'Deze foto is te groot. Maximaal '.($maxKilobytes / 1024).' MB.',
            ]);
        }

        $normalized = $this->photoUploadNormalizer->normalize($file);

        try {
            $disk = (string) config('filesystems.media', 'local');
            $directory = 'intakes/'.$intake->uuid.'/'.self::QUESTION_KEY;
            $path = $directory.'/'.Str::ulid()->toBase32().'.'.$normalized->extension;

            if (! Storage::disk($disk)->put($path, File::get($normalized->absolutePath))) {
                throw ValidationException::withMessages([
                    'photo' => 'Upload mislukt. Probeer het opnieuw.',
                ]);
            }

            $sortOrder = IntakeUpload::query()
                ->where('intake_id', $intake->id)
                ->where('question_key', self::QUESTION_KEY)
                ->count() + 1;

            return IntakeUpload::query()->create([
                'intake_id' => $intake->id,
                'question_key' => self::QUESTION_KEY,
                'section_instance_key' => null,
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $normalized->originalFilename,
                'mime_type' => $normalized->mime,
                'size_bytes' => $normalized->sizeBytes,
                'checksum' => $normalized->checksum,
                'sort_order' => $sortOrder,
            ]);
        } finally {
            foreach ($normalized->cleanupPaths as $cleanupPath) {
                @unlink($cleanupPath);
            }
        }
    }
}
