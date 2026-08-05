<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Services\PublicDemoWorkspaceProvisioner;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Starts a public demo session by provisioning an ephemeral installer tenant.
 * The intake itself is created later via the normal "Nieuwe opname" flow.
 */
final class StartDemoIntake
{
    public function __construct(
        private readonly PublicDemoWorkspaceProvisioner $workspaceProvisioner,
    ) {}

    public function handle(): User
    {
        if (! (bool) config('intake.demo.enabled', true)) {
            throw ValidationException::withMessages([
                'demo' => 'Demo is uitgeschakeld in deze omgeving.',
            ]);
        }

        $suffix = Str::lower((string) Str::ulid());

        try {
            return $this->workspaceProvisioner->provision($suffix);
        } catch (Throwable $exception) {
            throw $exception;
        }
    }
}
