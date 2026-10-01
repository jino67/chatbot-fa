<?php

namespace App\Support;

use App\Mail\Notice;
use App\Models\Bot;
use App\Models\ChannelRequest;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Notify\Events;

/**
 * Tous les e-mails de la plateforme avec des données d'exemple : sert à les regarder avant de les envoyer (Administration, E-mails),
 * à s'envoyer un essai, et à vérifier dans les tests qu'aucun ne casse. Les e-mails construits par Events (abonnement, paiement,
 * WhatsApp, bienvenue, alertes de l'équipe) sont capturés sans rien envoyer : il n'y a qu'un seul texte à tenir à jour.
 */
final class MailCatalog
{
    public const GROUPS = ['client' => 'Pour les clients', 'team' => 'Pour l\'équipe'];

    /** @return array<string,array{label:string, group:string, note:string}> */
    public static function all(): array
    {
        return [
            'welcome' => ['label' => 'Bienvenue après l\'inscription', 'group' => 'client', 'note' => 'Envoyé une fois, juste après la création du compte.'],
            'lead' => ['label' => 'Commande, rendez-vous ou devis à confirmer', 'group' => 'client', 'note' => 'Alerte d\'une demande, selon les choix de la page Alertes.'],
            'handoff' => ['label' => 'Un client attend une réponse humaine', 'group' => 'client', 'note' => 'Quand l\'assistant passe la main à une personne.'],
            'subscriptionEnding' => ['label' => 'Abonnement bientôt échu', 'group' => 'client', 'note' => 'Quelques jours avant l\'échéance.'],
            'subscriptionLate' => ['label' => 'Abonnement échu (période de grâce)', 'group' => 'client', 'note' => 'Le jour de l\'échéance, avec le délai pour renouveler.'],
            'subscriptionEnded' => ['label' => 'Abonnement terminé', 'group' => 'client', 'note' => 'Après la période de grâce : retour à l\'offre gratuite.'],
            'trialEnding' => ['label' => 'Essai gratuit bientôt terminé', 'group' => 'client', 'note' => 'Quelques jours avant la fin de l\'essai.'],
            'trialEnded' => ['label' => 'Essai gratuit terminé', 'group' => 'client', 'note' => 'L\'assistant est en pause.'],
            'paymentReceived' => ['label' => 'Reçu de paiement', 'group' => 'client', 'note' => 'Quand l\'équipe enregistre un paiement : sert de reçu.'],
            'whatsappNearLimit' => ['label' => 'Messages WhatsApp bientôt épuisés', 'group' => 'client', 'note' => 'À 90 % du volume du mois.'],
            'whatsappBlocked' => ['label' => 'Volume WhatsApp atteint', 'group' => 'client', 'note' => 'Une fois par jour au plus.'],
            'whatsappActivated' => ['label' => 'WhatsApp activé', 'group' => 'client', 'note' => 'Quand l\'équipe active le numéro du client.'],
            'password-reset' => ['label' => 'Réinitialisation du mot de passe', 'group' => 'client', 'note' => 'Lien valable une heure, une seule fois.'],
            'planRequested' => ['label' => 'Demande d\'offre', 'group' => 'team', 'note' => 'Quand un client demande à changer d\'offre.'],
            'optionRequested' => ['label' => 'Demande d\'option à la carte', 'group' => 'team', 'note' => 'Par exemple l\'import des discussions WhatsApp.'],
            'whatsappRequested' => ['label' => 'Demande d\'activation WhatsApp', 'group' => 'team', 'note' => 'Quand un client demande l\'activation de son numéro.'],
            'walletLow' => ['label' => 'Solde Twilio bas', 'group' => 'team', 'note' => 'Une fois par jour au plus.'],
            'digest' => ['label' => 'Résumé hebdomadaire des statistiques', 'group' => 'team', 'note' => 'Chaque lundi matin.'],
            'test' => ['label' => 'E-mail d\'essai', 'group' => 'team', 'note' => 'Celui de la commande platform:mail-test.'],
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function subject(string $key): string
    {
        $notice = self::notice($key);

        return $notice ? $notice->subjectLine : match ($key) {
            'lead' => 'Commande à confirmer : 2 boubous brodés',
            'handoff' => 'Un client attend une réponse : Assistant Boutique Awa',
            'password-reset' => 'Réinitialisation de votre mot de passe',
            'digest' => 'Votre semaine sur Kouma : 142 visite(s)',
            default => self::all()[$key]['label'],
        };
    }

    /** L'e-mail en HTML, tel qu'il arrive dans la boîte. */
    public static function html(string $key): string
    {
        if ($notice = self::notice($key)) {
            return $notice->render();
        }

        return match ($key) {
            'lead' => view('emails.lead', [
                'lead' => (new Lead(['kind' => Lead::ORDER, 'title' => 'Commande : 2 boubous brodés', 'summary' => 'Livraison à Bobo-Dioulasso demain avant 17 h, paiement à la livraison.', 'contact_name' => 'Awa', 'contact_phone' => '+226 70 12 34 56']))->setRelation('bot', new Bot(['name' => 'Assistant Boutique Awa'])),
                'reminder' => false,
                'url' => route('leads.index'),
                'conversationUrl' => route('conversations.show', [1, 1]),
            ])->render(),
            'handoff' => view('emails.handoff', [
                'conversation' => (new Conversation(['channel' => 'whatsapp', 'contact_name' => 'Moussa']))->setRelation('bot', new Bot(['name' => 'Assistant Boutique Awa'])),
                'messages' => collect([
                    new Message(['role' => Message::USER, 'content' => 'Bonjour, je voudrais parler à quelqu\'un pour une grosse commande.']),
                    new Message(['role' => Message::ASSISTANT, 'content' => 'Bien sûr, je préviens un membre de l\'équipe qui vous répondra ici dès que possible.']),
                ]),
                'url' => route('conversations.show', [1, 1]),
                'reason' => 'demande explicite du client',
            ])->render(),
            'password-reset' => view('emails.password-reset', ['brandName' => app('platform.brand')['name'], 'name' => 'Awa Ouédraogo', 'minutes' => 60, 'url' => url('/reset-password/exemple?email=awa@exemple.test')])->render(),
            'digest' => view('emails.analytics-digest', self::digestSample())->render(),
            default => '',
        };
    }

    /** Pour les e-mails construits par Events (ou la commande d'essai) : le Notice rempli avec des données d'exemple. */
    public static function notice(string $key): ?Notice
    {
        if ($key === 'test') {
            return new Notice(
                subjectLine: "Essai d'envoi : ".config('app.name'),
                heading: "L'envoi d'e-mails fonctionne",
                paragraphs: ["Ceci est un e-mail d'essai envoyé par ".config('app.name').'.', "Si vous le lisez, l'envoi fonctionne."],
                actionLabel: 'Ouvrir le site',
                actionUrl: url('/'),
                facts: ['Expéditeur' => (string) config('mail.from.address')],
                tone: 'success',
                settings: false,
            );
        }

        if (! in_array($key, ['welcome', 'subscriptionEnding', 'subscriptionLate', 'subscriptionEnded', 'trialEnding', 'trialEnded', 'paymentReceived', 'whatsappNearLimit', 'whatsappBlocked', 'whatsappActivated', 'planRequested', 'optionRequested', 'whatsappRequested', 'walletLow'], true)) {
            return null;
        }

        $found = null;
        $events = app(Events::class)->capturing(function (string $name, Notice $notice) use (&$found) {
            $found = $notice;
        });

        $workspace = (new Workspace(['name' => 'Boutique Awa', 'plan' => Plan::query()->orderByDesc('id')->value('slug') ?? 'pro', 'plan_ends_at' => now()->addDays(3)]))->forceFill(['id' => 1]);
        $bot = (new Bot(['name' => 'Assistant Boutique Awa']))->forceFill(['id' => 1]);
        $user = (new User(['name' => 'Awa Ouédraogo', 'email' => 'awa@exemple.test']))->forceFill(['id' => 1]);
        $payment = (new Payment(['amount' => 40000, 'currency' => 'XOF', 'method' => array_key_first(Payment::METHODS), 'reference' => 'OM-2026-0412', 'period_months' => 1]))
            ->forceFill(['period_start' => now(), 'period_end' => now()->addMonth()]);
        $request = (new ChannelRequest(['business_name' => 'Boutique Awa', 'phone_number' => '+226 70 12 34 56']))->forceFill(['id' => 1]);
        $plan = new Plan(['name' => 'Pro']);

        match ($key) {
            'welcome' => $events->welcome($user, $workspace),
            'subscriptionEnding' => $events->subscriptionEnding($workspace, 3),
            'subscriptionLate' => $events->subscriptionLate($workspace, (int) config('platform.billing.grace_days')),
            'subscriptionEnded' => $events->subscriptionEnded($workspace, true, 'Découverte'),
            'trialEnding' => $events->trialEnding($workspace, 3),
            'trialEnded' => $events->trialEnded($workspace),
            'paymentReceived' => $events->paymentReceived($workspace, $payment, $plan),
            'whatsappNearLimit' => $events->whatsappNearLimit($workspace, 905, 1000),
            'whatsappBlocked' => $events->whatsappBlocked($workspace),
            'whatsappActivated' => $events->whatsappActivated($workspace, $bot),
            'planRequested' => $events->planRequested($workspace, 'Pro', $user, 'Nous voulons WhatsApp pour nos deux boutiques.'),
            'optionRequested' => $events->optionRequested($workspace, 'Import des discussions WhatsApp', $user),
            'whatsappRequested' => $events->whatsappRequested($request, $bot),
            'walletLow' => $events->walletLow(12.5, 'USD', 20.0),
        };

        return $found;
    }

    /** @return array<string,mixed> */
    private static function digestSample(): array
    {
        return [
            'brandName' => app('platform.brand')['name'], 'visits' => 142, 'visitors' => 118, 'pageviews' => 311, 'delta' => 18.5, 'bounce' => 38.2,
            'peak' => ['day' => 'Jeudi', 'hour' => 20, 'count' => 17],
            'sources' => [['label' => 'Accès direct', 'sessions' => 61], ['label' => 'Moteurs de recherche', 'sessions' => 40], ['label' => 'Réseaux sociaux', 'sessions' => 25]],
            'pages' => [['name' => 'Accueil', 'views' => 120], ['name' => 'Inscription', 'views' => 44], ['name' => 'Ressources', 'views' => 31]],
            'cta' => ['name' => 'Créer mon assistant', 'clicks' => 37],
            'insights' => [['level' => 'info', 'title' => 'Le moment le plus chargé', 'text' => 'Les visites se concentrent le jeudi, vers 20 h.', 'tab' => 'affluence']],
            'active' => ['dau' => 3, 'wau' => 7, 'mau' => 11, 'stickiness' => 27.3, 'workspaces' => 14],
            'risk' => [['name' => 'Salon Fatou']],
            'url' => route('admin.statistics.index'),
            'from' => '24 septembre', 'to' => '30 septembre 2026',
        ];
    }
}
