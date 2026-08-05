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

    /**
     * @return list<string>
     */
    public function shortCustomerQuestionKeys(): array
    {
        $configured = config('intake.demo.short_customer_question_keys', [
            'request_reason',
            'cooling_heating',
            'building_type',
            'outdoor_location',
            'free_group_known',
        ]);

        if (! is_array($configured)) {
            return [];
        }

        $keys = [];
        foreach ($configured as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
