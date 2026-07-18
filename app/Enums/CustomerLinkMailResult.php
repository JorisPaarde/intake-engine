<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerLinkMailResult: string
{
    case Sent = 'sent';
    case SkippedDemo = 'skipped_demo';
    case SkippedInvalid = 'skipped_invalid';
    case SkippedLogMailer = 'skipped_log_mailer';
    case Failed = 'failed';

    public function flashMessage(string $context): string
    {
        return match ($this) {
            self::Sent => match ($context) {
                'created' => 'Opname aangemaakt. We hebben de klantlink gemaild; je kunt de link ook kopiëren.',
                'regenerated' => 'Nieuwe klantlink gegenereerd en gemaild.',
                'resend' => 'Klantlink opnieuw gemaild.',
                default => 'Klantlink gemaild.',
            },
            self::SkippedLogMailer => match ($context) {
                'created' => 'Opname aangemaakt. De klantlink staat klaar om te kopiëren. Automatische e-mail is nog niet geconfigureerd.',
                'regenerated' => 'Nieuwe klantlink gegenereerd. Kopieer de link handmatig; automatische e-mail is nog niet geconfigureerd.',
                'resend' => 'E-mail kon niet worden verstuurd: automatische e-mail is nog niet geconfigureerd. Kopieer de link handmatig.',
                default => 'Automatische e-mail is nog niet geconfigureerd. Kopieer de klantlink handmatig.',
            },
            self::Failed => match ($context) {
                'created' => 'Opname aangemaakt. De e-mail kon niet worden verstuurd; kopieer de klantlink handmatig.',
                'regenerated' => 'Nieuwe klantlink gegenereerd. De e-mail kon niet worden verstuurd; kopieer de link handmatig.',
                'resend' => 'De e-mail kon niet worden verstuurd. Kopieer de klantlink handmatig.',
                default => 'De e-mail kon niet worden verstuurd. Kopieer de klantlink handmatig.',
            },
            self::SkippedInvalid => match ($context) {
                'resend' => 'Deze klantlink is niet meer geldig. Genereer eerst een nieuwe link.',
                default => 'Opname aangemaakt. De klantlink staat klaar om te kopiëren.',
            },
            self::SkippedDemo => 'Demo-opname aangemaakt (geen e-mail).',
        };
    }
}
