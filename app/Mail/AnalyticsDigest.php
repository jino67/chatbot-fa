<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Le résumé de la semaine envoyé au super administrateur : l'essentiel des statistiques sans ouvrir la console. */
class AnalyticsDigest extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string,mixed> $data */
    public function __construct(public array $data) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre semaine sur '.$this->data['brandName'].' : '.$this->data['visits'].' visite(s)');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.analytics-digest', with: $this->data);
    }
}
