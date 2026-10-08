<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeReplacedAccessToken;

final class RecordReplacedAccessToken
{
    public function handle(Intake $intake, ?string $previousToken): void
    {
        if (! is_string($previousToken) || $previousToken === '') {
            return;
        }

        IntakeReplacedAccessToken::query()->updateOrCreate(
            ['token_hash' => IntakeReplacedAccessToken::hashToken($previousToken)],
            [
                'intake_id' => $intake->id,
                'replaced_at' => now(),
            ],
        );
    }
}
