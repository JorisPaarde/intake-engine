<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Mail\CustomerIntakeReminderMail;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Enums\CustomerReminderMailResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendCustomerIntakeReminder
{
    public function handle(Intake $intake): CustomerReminderMailResult
    {
        if ($intake->is_demo) {
            return CustomerReminderMailResult::SkippedDemo;
        }

        if ($intake->reminder_sent_at !== null) {
            return CustomerReminderMailResult::SkippedAlreadySent;
        }

        if (blank($intake->customer_email) || ! $intake->isTokenValid()) {
            return CustomerReminderMailResult::SkippedInvalid;
        }

        $days = max(1, (int) config('intake.reminder.days', 3));
        $dueAt = $intake->created_at?->copy()->addDays($days);

        if ($dueAt === null || $dueAt->isFuture()) {
            return CustomerReminderMailResult::SkippedNotDue;
        }

        // Never put access tokens into the log mailer (ADR-0002).
        if (config('mail.default') === 'log') {
            return CustomerReminderMailResult::SkippedLogMailer;
        }

        try {
            Mail::to($intake->customer_email)->send(new CustomerIntakeReminderMail($intake));
        } catch (Throwable $exception) {
            Log::warning('Failed to send customer intake reminder mail', [
                'intake_id' => $intake->id,
                'exception' => $exception::class,
            ]);

            return CustomerReminderMailResult::Failed;
        }

        $intake->forceFill([
            'reminder_sent_at' => now(),
        ])->save();

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'system',
            'actor_id' => null,
            'event' => 'customer_reminder_mailed',
            // No token, URL, or full e-mail address (ADR-0002 / privacy).
            'properties' => [
                'mailer' => (string) config('mail.default'),
                'reminder_days' => $days,
            ],
            'created_at' => now(),
        ]);

        return CustomerReminderMailResult::Sent;
    }
}
