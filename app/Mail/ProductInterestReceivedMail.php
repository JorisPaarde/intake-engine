<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ProductInterest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ProductInterestReceivedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ProductInterest $interest,
    ) {}

    public function envelope(): Envelope
    {
        $isDemoPdfLead = str_contains((string) $this->interest->message, 'source=demo_pdf_request');

        return new Envelope(
            replyTo: [
                new Address($this->interest->email, $this->interest->contact_name),
            ],
            subject: $isDemoPdfLead
                ? 'Demo-lead: PDF-aanvraag Digitale Opname'
                : 'Nieuwe interesse in Digitale Opname',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.product-interest-received',
        );
    }
}
