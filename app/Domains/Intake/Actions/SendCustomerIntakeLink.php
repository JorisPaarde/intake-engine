<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Mail\CustomerIntakeLinkMail;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Enums\CustomerLinkMailResult;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendCustomerIntakeLink
{
    public function handle(Intake $intake, ?User $actor = null): CustomerLinkMailResult
    {
        if ($intake->is_demo) {
            return CustomerLinkMailResult::SkippedDemo;
        }

        if (blank($intake->customer_email) || ! $intake->isTokenValid()) {
            return CustomerLinkMailResult::SkippedInvalid;
        }

        // Never put access tokens into the log mailer (ADR-0002).
        if (config('mail.default') === 'log') {
            return CustomerLinkMailResult::SkippedLogMailer;
        }

        try {
            Mail::to($intake->customer_email)->send(new CustomerIntakeLinkMail($intake));
        } catch (Throwable $exception) {
            Log::warning('Failed to send customer intake link mail', [
                'intake_id' => $intake->id,
                'exception' => $exception::class,
            ]);

            return CustomerLinkMailResult::Failed;
        }

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => $actor !== null ? 'user' : 'system',
            'actor_id' => $actor?->id,
            'event' => 'customer_link_mailed',
            // No token, URL, or full e-mail address (ADR-0002 / privacy).
            'properties' => [
                'mailer' => (string) config('mail.default'),
            ],
            'created_at' => now(),
        ]);

        return CustomerLinkMailResult::Sent;
    }
}
