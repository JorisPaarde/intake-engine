<?php

declare(strict_types=1);

namespace App\Domains\Intake\Mail;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class CustomerFollowUpRequestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Intake $intake,
        public readonly IntakeFollowUpRound $round,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nog een paar gegevens nodig voor je aanvraag',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.customer-follow-up-request',
            with: [
                'customerName' => $this->intake->customer_name,
                'customerUrl' => $this->intake->customerUrl(),
                'expiresAt' => $this->intake->token_expires_at,
                'appName' => (string) config('app.name'),
            ],
        );
    }
}
