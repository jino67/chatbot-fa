<?php

namespace App\Mail;

use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HandoffRequested extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Conversation $conversation, public string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Un client attend une réponse : {$this->conversation->bot->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.handoff', with: [
            'conversation' => $this->conversation,
            'messages' => $this->conversation->messages()->latest('id')->limit(6)->get()->reverse(),
            'url' => route('conversations.show', [$this->conversation->bot_id, $this->conversation->id]),
        ]);
    }
}
