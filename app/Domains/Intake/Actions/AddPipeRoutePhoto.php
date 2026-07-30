<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\AI\Actions\AnalyzeRoutePhoto;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Models\PipeRouteSegment;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Enums\AircoConnectionStatus;
use App\Enums\PipeRouteStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $segment = DB::transaction(function () use ($session, $upload, $label): PipeRouteSegment {
            $intake = Intake::query()->whereKey($session->intake_id)->lockForUpdate()->firstOrFail();
            $session = PipeRouteSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $upload = IntakeUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();

            if ($session->intake_id !== $intake->id
                || $upload->intake_id !== $intake->id
                || ! in_array($session->status, [PipeRouteStatus::Collecting, PipeRouteStatus::Proposed], true)) {
                throw ValidationException::withMessages([
                    'upload' => 'Deze foto kan niet aan deze leidingroute worden gekoppeld.',
                ]);
            }

            if ($session->status === PipeRouteStatus::Proposed) {
                $session->update([
                    'status' => PipeRouteStatus::Collecting,
                    'confidence' => null,
                    'proposed_route' => null,
                    'alternative_route' => null,
                    'uncertainties' => null,
                    'missing_checks' => null,
                ]);
                $session->connection?->update([
                    'status' => AircoConnectionStatus::NeedsEvidence,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
            }

            $sequence = (int) $session->segments()->max('sequence') + 1;

            return $session->segments()->create([
                'intake_upload_id' => $upload->id,
                'sequence' => $sequence,
                'label' => $label,
            ]);
        }, 3);

        $segment = $this->analyzeRoutePhoto->handle($segment);

        DB::transaction(function () use ($session, $segment): void {
            Intake::query()->whereKey($session->intake_id)->lockForUpdate()->firstOrFail();
            $session = PipeRouteSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $segment = PipeRouteSegment::query()->whereKey($segment->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== PipeRouteStatus::Collecting
                || (int) $session->segments()->max('sequence') !== $segment->sequence) {
                return;
            }

            $instruction = $segment->analysis['next_photo_instruction'] ?? null;
            $session->update([
                'next_photo_instruction' => is_string($instruction) && trim($instruction) !== ''
                    ? trim($instruction)
                    : null,
            ]);
        }, 3);

        return $segment;
    }
}
