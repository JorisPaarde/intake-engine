<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ChooseDemoContributionPath
{
    public function handle(Intake $intake, User $actor, string $path): Intake
    {
        if (! $intake->is_demo) {
            throw new InvalidArgumentException('Alleen demo-opnames hebben een demopadkeuze.');
        }

        if ((int) $intake->created_by !== (int) $actor->id
            || (int) $intake->company_id !== (int) $actor->company_id) {
            throw new InvalidArgumentException('Deze demopadkeuze hoort bij een andere sessie.');
        }

        if ($path !== 'customer' && $path !== 'installer') {
            throw ValidationException::withMessages([
                'path' => 'Kies of je doorgaat als klant of zelf de opname doet.',
            ]);
        }

        return DB::transaction(function () use ($intake, $actor, $path): Intake {
            if ($path === 'installer') {
                $intake->forceFill([
                    'workflow_mode' => ContributionMode::Installer,
                    'status' => IntakeStatus::Draft,
                    'customer_access_enabled' => false,
                ])->save();
            } else {
                $intake->forceFill([
                    'workflow_mode' => ContributionMode::Customer,
                    'status' => IntakeStatus::Sent,
                    'customer_access_enabled' => true,
                    'token_expires_at' => now()->addHours(max(1, (int) config('intake.demo.ttl_hours', 2))),
                ])->save();
            }

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'event' => 'demo_path_chosen',
                'properties' => ['path' => $path],
                'created_at' => now(),
            ]);

            return $intake->fresh() ?? $intake;
        });
    }
}
