<?php

declare(strict_types=1);

namespace App\Domains\Intake\Mail;

use App\Domains\Intake\Models\Intake;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class CustomerIntakeLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Intake $intake,
    ) {}

    public function envelope(): Envelope
    {
        $appName = (string) config('app.name');

        return new Envelope(
            subject: "Je digitale opname — open je link ({$appName})",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.customer-intake-link',
            with: [
                'customerName' => $this->intake->customer_name,
                'customerUrl' => $this->intake->customerUrl(),
                'expiresAt' => $this->intake->token_expires_at,
                'appName' => (string) config('app.name'),
            ],
        );
    }
}
