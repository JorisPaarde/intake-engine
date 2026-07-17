<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Enums\IntakeStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RevokeIntakeAccess
{
    public function handle(Intake $intake, User $actor): Intake
    {
        return DB::transaction(function () use ($intake, $actor): Intake {
            $intake->update([
                'token_revoked_at' => now(),
                'status' => IntakeStatus::Cancelled,
            ]);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'event' => 'intake_access_revoked',
                'properties' => null,
                'created_at' => now(),
            ]);

            return $intake->fresh() ?? $intake;
        });
    }
}
