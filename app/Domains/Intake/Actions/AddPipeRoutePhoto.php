<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\AI\Actions\AnalyzeRoutePhoto;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Models\PipeRouteSegment;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Enums\PipeRouteStatus;

/**
 * Koppelt een geüploade foto als volgend routesegment aan de sessie en laat het direct
 * beoordelen. De volgende gerichte foto-instructie van het model wordt op de sessie
 * bijgewerkt, zodat de begeleide flow steeds om één specifieke foto kan vragen.
 */
final class AddPipeRoutePhoto
{
    public function __construct(
        private readonly AnalyzeRoutePhoto $analyzeRoutePhoto,
    ) {}

    public function handle(PipeRouteSession $session, IntakeUpload $upload, ?string $label = null): PipeRouteSegment
    {
        $sequence = (int) $session->segments()->max('sequence') + 1;

        $segment = $session->segments()->create([
            'intake_upload_id' => $upload->id,
            'sequence' => $sequence,
            'label' => $label,
        ]);

        $segment = $this->analyzeRoutePhoto->handle($segment);

        // Terug naar 'verzamelen' zodra er een nieuwe foto bijkomt na een voorstel.
        $updates = [];

        if ($session->status === PipeRouteStatus::Proposed) {
            $updates['status'] = PipeRouteStatus::Collecting;
        }

        $instruction = $segment->analysis['next_photo_instruction'] ?? null;
        $updates['next_photo_instruction'] = is_string($instruction) && trim($instruction) !== ''
            ? trim($instruction)
            : null;

        $session->update($updates);

        return $segment;
    }
}
