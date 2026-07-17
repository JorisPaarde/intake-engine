<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResolveIntakeByAccessToken
{
    public function handle(string $token): Intake
    {
        $intake = Intake::query()
            ->where('access_token', $token)
            ->first();

        if ($intake === null || ! $intake->isTokenValid()) {
            throw new NotFoundHttpException('Deze intake-link is ongeldig of verlopen.');
        }

        return $intake;
    }
}
