<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Exceptions\CustomerLinkUnavailableException;
use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResolveIntakeByAccessToken
{
    public function handle(string $token): Intake
    {
        $intake = Intake::query()
            ->where('access_token', $token)
            ->first();

        if ($intake === null) {
            throw new NotFoundHttpException('Deze intake-link is ongeldig of verlopen.');
        }

        if ($intake->isTokenValid()) {
            return $intake;
        }

        throw new CustomerLinkUnavailableException(
            reason: $this->unavailableReason($intake),
            intake: $intake,
            message: 'Deze link is niet meer geldig.',
        );
    }

    private function unavailableReason(Intake $intake): string
    {
        if (in_array($intake->status, [IntakeStatus::Completed, IntakeStatus::Reviewed], true)) {
            return 'used';
        }

        if ($intake->token_revoked_at !== null) {
            return 'revoked';
        }

        if ($intake->token_expires_at !== null && $intake->token_expires_at->isPast()) {
            return 'expired';
        }

        if (! $intake->customer_access_enabled) {
            return 'disabled';
        }

        return 'unavailable';
    }
}
