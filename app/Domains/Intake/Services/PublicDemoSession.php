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
            && ! $request->session()->has('public_demo_intake_id')
            && ! $request->session()->has('public_demo_intake_ids')) {
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

    /**
     * Active (coach/rapport) intake id — the one last opened or created.
     */
    public function intakeId(Request $request): ?int
    {
        $id = $request->session()->get('public_demo_intake_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * All demo intakes this session may open (own + sample). Migrates legacy
     * single `public_demo_intake_id` into the list when needed.
     *
     * @return list<int>
     */
    public function allowedIntakeIds(Request $request): array
    {
        $raw = $request->session()->get('public_demo_intake_ids', []);
        $ids = [];

        if (is_array($raw)) {
            foreach ($raw as $value) {
                if (is_numeric($value)) {
                    $ids[] = (int) $value;
                }
            }
        }

        $active = $this->intakeId($request);
        if ($active !== null) {
            $ids[] = $active;
        }

        $ids = array_values(array_unique($ids));

        /** @var list<int> $ids */
        if ($ids !== [] && $request->session()->get('public_demo_intake_ids') !== $ids) {
            $request->session()->put('public_demo_intake_ids', $ids);
        }

        return $ids;
    }

    public function hasAnyIntake(Request $request): bool
    {
        return $this->allowedIntakeIds($request) !== [];
    }

    public function allowsIntake(Request $request, int $intakeId): bool
    {
        return in_array($intakeId, $this->allowedIntakeIds($request), true);
    }

    /**
     * Remember an intake as allowed; optionally set it as the active one.
     */
    public function rememberIntake(Request $request, int $intakeId, bool $setActive = true): void
    {
        $ids = $this->allowedIntakeIds($request);
        if (! in_array($intakeId, $ids, true)) {
            $ids[] = $intakeId;
        }

        $request->session()->put('public_demo_intake_ids', $ids);

        if ($setActive) {
            $request->session()->put('public_demo_intake_id', $intakeId);
        }
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
            'public_demo_intake_ids',
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
            || $request->session()->has('public_demo_intake_id')
            || $request->session()->has('public_demo_intake_ids');
    }

    public function resolveIntake(Request $request, ?int $preferredId = null): ?Intake
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $intakeId = $preferredId ?? $this->intakeId($request);

        if ($intakeId === null) {
            return null;
        }

        if (! $this->allowsIntake($request, $intakeId)) {
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
