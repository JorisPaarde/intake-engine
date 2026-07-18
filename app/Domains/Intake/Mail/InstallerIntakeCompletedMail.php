<?php

declare(strict_types=1);

namespace App\Domains\Intake\Mail;

use App\Domains\Intake\Models\Intake;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class InstallerIntakeCompletedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Intake $intake,
    ) {}

    public function envelope(): Envelope
    {
        $appName = (string) config('app.name');
        $customer = $this->intake->customer_name;

        return new Envelope(
            subject: "Opname afgerond — {$customer} ({$appName})",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.installer-intake-completed',
            with: [
                'customerName' => $this->intake->customer_name,
                'address' => $this->intake->fullAddress(),
                'completedAt' => $this->intake->completed_at,
                'intakeUrl' => route('intakes.show', $this->intake),
                'appName' => (string) config('app.name'),
            ],
        );
    }
}
