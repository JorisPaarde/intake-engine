<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Mail\InstallerIntakeCompletedMail;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Enums\InstallerCompletionMailResult;
use App\Enums\IntakeStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendInstallerIntakeCompleted
{
    public function handle(Intake $intake): InstallerCompletionMailResult
    {
        if ($intake->is_demo) {
            return InstallerCompletionMailResult::SkippedDemo;
        }

        if ($intake->status !== IntakeStatus::Completed) {
            return InstallerCompletionMailResult::SkippedInvalid;
        }

        $intake->loadMissing('creator');
        $installer = $intake->creator;

        if ($installer === null || blank($installer->email)) {
            return InstallerCompletionMailResult::SkippedInvalid;
        }

        // Consistent with BL-004: skip when mailer is log (staging without SMTP).
        if (config('mail.default') === 'log') {
            return InstallerCompletionMailResult::SkippedLogMailer;
        }

        try {
            Mail::to($installer->email)->send(new InstallerIntakeCompletedMail($intake));
        } catch (Throwable $exception) {
            Log::warning('Failed to send installer intake completed mail', [
                'intake_id' => $intake->id,
                'exception' => $exception::class,
            ]);

            return InstallerCompletionMailResult::Failed;
        }

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'system',
            'actor_id' => null,
            'event' => 'installer_completion_mailed',
            // No customer e-mail, tokens, or full installer address (privacy).
            'properties' => [
                'mailer' => (string) config('mail.default'),
            ],
            'created_at' => now(),
        ]);

        return InstallerCompletionMailResult::Sent;
    }
}
