<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

final class DemoReportPdfMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $email,
        public readonly string $intakeUuid,
        public readonly string $pdfDisk,
        public readonly string $pdfPath,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Je demorapport van de Digitale Opname',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.demo-report-pdf',
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if (! Storage::disk($this->pdfDisk)->exists($this->pdfPath)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($this->pdfDisk, $this->pdfPath)
                ->as('demorapport-digitale-opname.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
