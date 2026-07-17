<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use Illuminate\Support\Str;

final class IntakeAccessTokenGenerator
{
    public function generate(): string
    {
        return Str::random(64);
    }
}
