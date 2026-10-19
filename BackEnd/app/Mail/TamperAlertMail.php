<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class TamperAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $oldFingerprint,
        public string $newFingerprint,
        public string $reportReference,
        public Carbon $occurredAt
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Alerte de sécurité — tentative de modification de vos choix');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.tamper-alert');
    }
}
