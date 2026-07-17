<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Services\IntakeAccessTokenGenerator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RegenerateIntakeAccessToken
{
    public function __construct(
        private readonly IntakeAccessTokenGenerator $tokenGenerator,
    ) {}

    public function handle(Intake $intake, User $actor): Intake
    {
        return DB::transaction(function () use ($intake, $actor): Intake {
            $intake->update([
                'access_token' => $this->tokenGenerator->generate(),
                'token_expires_at' => now()->addDays((int) config('intake.token_ttl_days', 60)),
                'token_revoked_at' => null,
            ]);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'event' => 'intake_token_regenerated',
                'properties' => null,
                'created_at' => now(),
            ]);

            return $intake->fresh() ?? $intake;
        });
    }
}
