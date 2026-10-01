<?php

namespace App\Mail;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** E-mail d'une commande à confirmer, d'un rendez-vous ou d'un devis (les demandes d'une personne ont HandoffRequested). */
class LeadAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Lead $lead, public bool $reminder = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->reminder ? 'Rappel : ' : '').$this->lead->label().' : '.$this->lead->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.lead', with: [
            'lead' => $this->lead,
            'reminder' => $this->reminder,
            'url' => route('leads.index'),
            'conversationUrl' => route('conversations.show', [$this->lead->bot_id, $this->lead->conversation_id]),
        ]);
    }
}
