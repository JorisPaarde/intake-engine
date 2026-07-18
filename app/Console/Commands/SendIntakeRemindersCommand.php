<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Intake\Actions\SendCustomerIntakeReminder;
use App\Domains\Intake\Models\Intake;
use App\Enums\CustomerReminderMailResult;
use App\Enums\IntakeStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class SendIntakeRemindersCommand extends Command
{
    protected $signature = 'intakes:send-reminders';

    protected $description = 'Stuur maximaal één herinneringsmail voor stilliggende klantintakes';

    public function handle(SendCustomerIntakeReminder $sendReminder): int
    {
        $days = max(1, (int) config('intake.reminder.days', 3));
        $cutoff = now()->subDays($days);

        $query = Intake::query()
            ->where('is_demo', false)
            ->whereNull('reminder_sent_at')
            ->whereIn('status', [IntakeStatus::Sent, IntakeStatus::InProgress])
            ->whereNull('token_revoked_at')
            ->where(function ($builder): void {
                $builder
                    ->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '>', now());
            })
            ->where('created_at', '<=', $cutoff);

        $sent = 0;
        $skipped = 0;

        $query->chunkById(50, function (Collection $intakes) use ($sendReminder, &$sent, &$skipped): void {
            foreach ($intakes as $intake) {
                $result = $sendReminder->handle($intake);

                if ($result === CustomerReminderMailResult::Sent) {
                    $sent++;
                } else {
                    $skipped++;
                }
            }
        });

        $this->info("Reminders sent: {$sent}; skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
