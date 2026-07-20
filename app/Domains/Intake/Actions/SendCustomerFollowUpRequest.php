<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Mail\CustomerFollowUpRequestMail;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Enums\CustomerLinkMailResult;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendCustomerFollowUpRequest
{
    public function handle(Intake $intake, IntakeFollowUpRound $round, User $actor): CustomerLinkMailResult
    {
        if ($intake->is_demo) {
            return CustomerLinkMailResult::SkippedDemo;
        }

        if ($intake->status !== IntakeStatus::AwaitingCustomer
            || $round->intake_id !== $intake->id
            || $round->status !== FollowUpRoundStatus::Open
            || blank($intake->customer_email)
            || ! $intake->isTokenValid()) {
            return CustomerLinkMailResult::SkippedInvalid;
        }

        if (config('mail.default') === 'log') {
            return CustomerLinkMailResult::SkippedLogMailer;
        }

        try {
            Mail::to($intake->customer_email)->send(new CustomerFollowUpRequestMail($intake, $round));
        } catch (Throwable $exception) {
            Log::warning('Failed to send customer follow-up request mail', [
                'intake_id' => $intake->id,
                'round_number' => $round->round_number,
                'exception' => $exception::class,
            ]);

            return CustomerLinkMailResult::Failed;
        }

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'user',
            'actor_id' => $actor->id,
            'event' => 'customer_follow_up_mailed',
            'properties' => [
                'round_number' => $round->round_number,
                'mailer' => (string) config('mail.default'),
            ],
            'created_at' => now(),
        ]);

        return CustomerLinkMailResult::Sent;
    }
}
