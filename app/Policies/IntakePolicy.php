<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Intake\Models\Intake;
use App\Models\User;

class IntakePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Intake $intake): bool
    {
        return $this->sameCompany($user, $intake);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Intake $intake): bool
    {
        return $this->sameCompany($user, $intake);
    }

    public function revoke(User $user, Intake $intake): bool
    {
        return $this->sameCompany($user, $intake);
    }

    public function review(User $user, Intake $intake): bool
    {
        return $this->sameCompany($user, $intake);
    }

    private function sameCompany(User $user, Intake $intake): bool
    {
        return (int) $user->company_id === (int) $intake->company_id;
    }
}
