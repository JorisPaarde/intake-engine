<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class PublicDemoSession
{
    public function __construct(
        private readonly PublicDemoWorkspaceProvisioner $workspaceProvisioner,
    ) {}

    public function isActive(Request $request): bool
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->workspaceProvisioner->isEphemeralUser($user)) {
            return false;
        }

        if (! (bool) $request->session()->get('public_demo_mode', false)
            && ! $request->session()->has('public_demo_intake_id')) {
            return false;
        }

        return $this->expiresAt($request) === null || $this->expiresAt($request)->isFuture();
    }

    public function expiresAt(Request $request): ?Carbon
    {
        $raw = $request->session()->get('public_demo_expires_at');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public function intakeId(Request $request): ?int
    {
        $id = $request->session()->get('public_demo_intake_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return list<string>
     */
    public static function sessionKeys(): array
    {
        return [
            'public_demo_mode',
            'public_demo_company_id',
            'public_demo_expires_at',
            'public_demo_guide_step',
            'public_demo_intake_id',
            'public_demo_path_chosen',
            'public_demo_scenario_loaded',
        ];
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::sessionKeys());
    }

    public function hasSessionFlags(Request $request): bool
    {
        return (bool) $request->session()->get('public_demo_mode', false)
            || $request->session()->has('public_demo_intake_id');
    }

    public function resolveIntake(Request $request): ?Intake
    {
        $user = $request->user();
        $intakeId = $this->intakeId($request);

        if (! $user instanceof User || $intakeId === null) {
            return null;
        }

        $query = Intake::query()
            ->whereKey($intakeId)
            ->where('company_id', $user->company_id)
            ->where('created_by', $user->id)
            ->where('is_demo', true);

        $expiresAt = $this->expiresAt($request);

        if ($expiresAt !== null) {
            $query->where('created_at', '>', now()->subHours(
                max(1, (int) config('intake.demo.ttl_hours', 2)),
            ));
        }

        return $query->first();
    }
}
