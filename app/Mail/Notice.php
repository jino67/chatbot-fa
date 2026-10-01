<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Le message d'information de la plateforme, aux couleurs de la marque : un titre, quelques phrases, un petit tableau de
 * faits, un bouton. Il remplace tous les anciens « Mail::raw » en texte brut (échéances, solde bas, demandes d'offre,
 * reçus de paiement...). Une version texte est jointe automatiquement : certaines messageries n'affichent que celle-là.
 */
class Notice extends Mailable
{
    use Queueable, SerializesModels;

    public const TONES = ['info', 'success', 'warning', 'danger'];

    /**
     * @param  list<string>  $paragraphs  chaque entrée est un paragraphe (texte simple, échappé à l'affichage)
     * @param  array<string,string|int|null>  $facts  libellé => valeur
     */
    public function __construct(
        public string $subjectLine,
        public string $heading,
        public array $paragraphs = [],
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        public array $facts = [],
        public ?string $greetingName = null,
        public string $tone = 'info',
        public ?string $reason = null,
        public ?string $preheader = null,
        public bool $settings = true,
    ) {
        $this->tone = in_array($tone, self::TONES, true) ? $tone : 'info';
    }

    /** « Bonjour Awa, » : le prénom seulement, ou « Bonjour, » quand on ne connaît personne. */
    public function greeting(): string
    {
        $first = trim((string) strtok((string) $this->greetingName, ' '));

        return $first !== '' ? "Bonjour {$first}," : 'Bonjour,';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notice', text: 'emails.notice-text', with: ['greeting' => $this->greeting()]);
    }
}
