<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\AiAlertErrorType;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AiFailureAlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly AiAlertErrorType $errorType,
        public readonly string $appName,
        public readonly string $occurredAt,
        public readonly ?int $httpStatus,
        public readonly string $sanitizedMessage,
        public readonly bool $isTest = false,
    ) {}

    public function envelope(): Envelope
    {
        $prefix = $this->isTest ? '[TEST] ' : '';

        return new Envelope(
            subject: $prefix.'AI-alert: '.$this->errorType->label().' — '.$this->appName,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.ai-failure-alert',
        );
    }
}
