<?php

namespace App\Notify;

use App\Mail\Notice;
use App\Models\Bot;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PlatformSettings;
use App\Support\Currency;
use Illuminate\Support\Facades\Mail;

/**
 * Les messages automatiques de la plateforme, écrits une seule fois : abonnement, paiement, WhatsApp, bienvenue, et les
 * alertes de l'équipe. Chaque événement part dans le centre de notifications (et sur les téléphones) et, quand c'est utile,
 * par e-mail aux couleurs de la marque. Un échec d'envoi est journalisé et ne casse jamais l'action qui l'a déclenché.
 */
final class Events
{
    /** Mode aperçu : au lieu d'envoyer, chaque e-mail est remis à cette fonction (clé, e-mail). Voir App\Support\MailCatalog. */
    private ?\Closure $capture = null;

    public function __construct(private readonly Notifier $notifier, private readonly PlatformSettings $settings) {}

    /** Une copie qui n'envoie rien : elle remet chaque e-mail construit à la fonction donnée. */
    public function capturing(\Closure $callback): static
    {
        $copy = clone $this;
        $copy->capture = $callback;

        return $copy;
    }

    /* ------------------------------------------------------------------------------------------------
       Clients : abonnement et paiement
       ------------------------------------------------------------------------------------------------ */

    public function subscriptionEnding(Workspace $workspace, int $days): void
    {
        $plan = $workspace->planModel();
        $title = "Votre abonnement se termine dans {$days} jour(s)";

        $this->toOwner($workspace, 'account', $title, 'Renouvelez-le pour ne pas interrompre vos assistants.', route('billing.show', [], false), fn (?string $name) => new Notice(
            subjectLine: $title,
            heading: $title,
            paragraphs: [
                "Votre offre {$plan?->name} se termine le ".($workspace->plan_ends_at?->locale('fr')->isoFormat('D MMMM YYYY') ?? 'bientôt').'.',
                "Pour ne pas interrompre vos assistants, renouvelez depuis la page Abonnement. Le paiement par Mobile Money est accepté : nous activons votre offre dès réception de la référence.",
            ],
            actionLabel: 'Renouveler mon abonnement',
            actionUrl: route('billing.show'),
            facts: ['Espace' => $workspace->name, 'Offre' => $plan?->name],
            greetingName: $name,
            tone: 'warning',
            reason: $this->ownerReason(),
        ));
    }

    public function subscriptionLate(Workspace $workspace, int $graceDays): void
    {
        $title = 'Votre abonnement est arrivé à échéance';

        $this->toOwner($workspace, 'account', $title, "Vous avez {$graceDays} jours pour le renouveler sans rien perdre.", route('billing.show', [], false), fn (?string $name) => new Notice(
            subjectLine: $title,
            heading: $title,
            paragraphs: ["Vous avez {$graceDays} jours pour le renouveler sans rien perdre : vos assistants continuent de répondre pendant ce délai.", 'Renouvelez depuis la page Abonnement.'],
            actionLabel: 'Renouveler mon abonnement',
            actionUrl: route('billing.show'),
            facts: ['Espace' => $workspace->name],
            greetingName: $name,
            tone: 'danger',
            reason: $this->ownerReason(),
        ));
    }

    public function subscriptionEnded(Workspace $workspace, bool $paused, string $defaultPlanName): void
    {
        $title = 'Votre abonnement est arrivé à échéance';
        $text = $paused
            ? 'Votre abonnement est arrivé à échéance et votre assistant est en pause. Vos assistants et vos données sont conservés ; pour le réactiver, renouvelez depuis la page Abonnement.'
            : "Votre espace est revenu à l'offre {$defaultPlanName}. Vos assistants et vos données sont conservés ; pour retrouver votre offre, renouvelez depuis la page Abonnement.";

        $this->toOwner($workspace, 'account', $title, $paused ? 'Votre assistant est en pause. Renouvelez pour le réactiver.' : "Votre espace est revenu à l'offre {$defaultPlanName}.", route('billing.show', [], false), fn (?string $name) => new Notice(
            subjectLine: $title,
            heading: $title,
            paragraphs: [$text],
            actionLabel: 'Renouveler mon abonnement',
            actionUrl: route('billing.show'),
            facts: ['Espace' => $workspace->name],
            greetingName: $name,
            tone: 'danger',
            reason: $this->ownerReason(),
        ), urgent: true);
    }

    public function trialEnding(Workspace $workspace, int $days): void
    {
        $title = "Votre essai gratuit se termine dans {$days} jour(s)";

        $this->toOwner($workspace, 'account', $title, 'Choisissez une offre pour que votre assistant continue de répondre.', route('billing.show', [], false), fn (?string $name) => new Notice(
            subjectLine: $title,
            heading: $title,
            paragraphs: ['Choisissez une offre depuis la page Abonnement avant cette date pour que votre assistant continue de répondre à vos visiteurs.', 'Besoin d\'aide pour choisir ? Écrivez-nous : nous vous conseillons selon votre activité.'],
            actionLabel: 'Choisir mon offre',
            actionUrl: route('billing.show'),
            facts: ['Espace' => $workspace->name],
            greetingName: $name,
            tone: 'warning',
            reason: $this->ownerReason(),
        ));
    }

    public function trialEnded(Workspace $workspace): void
    {
        $title = 'Votre essai gratuit est terminé';

        $this->toOwner($workspace, 'account', $title, 'Votre assistant est en pause. Choisissez une offre pour le réactiver.', route('billing.show', [], false), fn (?string $name) => new Notice(
            subjectLine: $title,
            heading: $title,
            paragraphs: ['Votre assistant est en pause : il ne répond plus à vos visiteurs. Vos assistants et vos données sont conservés.', 'Choisissez une offre depuis la page Abonnement pour le réactiver.'],
            actionLabel: 'Choisir mon offre',
            actionUrl: route('billing.show'),
            facts: ['Espace' => $workspace->name],
            greetingName: $name,
            tone: 'danger',
            reason: $this->ownerReason(),
        ), urgent: true);
    }

    public function paymentReceived(Workspace $workspace, Payment $payment, Plan $plan): void
    {
        $title = 'Paiement reçu, merci !';
        $until = $payment->period_end?->locale('fr')->isoFormat('D MMMM YYYY');

        $this->toOwner($workspace, 'account', $title, "Votre offre {$plan->name} est active jusqu'au {$until}.", route('billing.show', [], false), fn (?string $name) => new Notice(
            subjectLine: "Reçu de paiement : offre {$plan->name}",
            heading: $title,
            paragraphs: ["Nous avons bien reçu votre paiement. Votre offre {$plan->name} est active jusqu'au {$until}.", 'Conservez cet e-mail : il vous sert de reçu.'],
            actionLabel: 'Voir mon abonnement',
            actionUrl: route('billing.show'),
            facts: [
                'Espace' => $workspace->name,
                'Offre' => $plan->name,
                'Montant' => Currency::format((float) $payment->amount, (string) $payment->currency),
                'Moyen de paiement' => Payment::METHODS[$payment->method] ?? $payment->method,
                'Référence' => $payment->reference,
                'Période' => $payment->period_start?->locale('fr')->isoFormat('D MMM YYYY').' au '.$until,
            ],
            greetingName: $name,
            tone: 'success',
            reason: $this->ownerReason(),
        ));
    }

    /* ------------------------------------------------------------------------------------------------
       Clients : WhatsApp
       ------------------------------------------------------------------------------------------------ */

    public function whatsappNearLimit(Workspace $workspace, int $used, int $allowance): void
    {
        $title = 'Vos messages WhatsApp arrivent à leur limite';

        $this->toOwner($workspace, 'account', $title, "Vous avez utilisé {$used} messages sur {$allowance} ce mois-ci.", route('billing.show', [], false), fn (?string $name) => new Notice(
            subjectLine: $title,
            heading: $title,
            paragraphs: [
                "Vous avez utilisé {$used} messages WhatsApp sur {$allowance} inclus dans votre offre ce mois-ci.",
                "Passé ce volume, votre assistant ne pourra plus répondre sur WhatsApp. Rechargez des messages (Mobile Money accepté) ou changez d'offre depuis la page Abonnement.",
            ],
            actionLabel: 'Recharger mes messages',
            actionUrl: route('billing.show'),
            facts: ['Espace' => $workspace->name],
            greetingName: $name,
            tone: 'warning',
            reason: $this->ownerReason(),
        ));
    }

    public function whatsappBlocked(Workspace $workspace): void
    {
        $title = 'Votre assistant ne peut plus répondre sur WhatsApp';

        $this->toOwner($workspace, 'account', $title, 'Le volume de messages de votre offre est atteint.', route('billing.show', [], false), fn (?string $name) => new Notice(
            subjectLine: 'Volume de messages WhatsApp atteint',
            heading: $title,
            paragraphs: [
                "Votre assistant n'a pas pu répondre à un client sur WhatsApp : le volume de messages de votre offre est atteint.",
                "Rechargez des messages (Mobile Money accepté) ou changez d'offre depuis la page Abonnement. Vos clients continuent d'être servis sur votre site web.",
            ],
            actionLabel: 'Ouvrir mon abonnement',
            actionUrl: route('billing.show'),
            facts: ['Espace' => $workspace->name],
            greetingName: $name,
            tone: 'danger',
            reason: $this->ownerReason(),
        ), urgent: true);
    }

    public function whatsappActivated(Workspace $workspace, Bot $bot): void
    {
        $title = 'WhatsApp est activé pour '.$bot->name;

        $this->toOwner($workspace, 'account', $title, 'Vos clients peuvent maintenant écrire à votre assistant sur WhatsApp.', route('channels.show', $bot, false), fn (?string $name) => new Notice(
            subjectLine: $title,
            heading: 'Votre assistant répond maintenant sur WhatsApp',
            paragraphs: [
                "Bonne nouvelle : l'activation est terminée. Vos clients peuvent écrire à « {$bot->name} » sur WhatsApp, et il leur répond à toute heure à partir de vos informations.",
                'Faites un essai avec votre propre téléphone, puis annoncez votre numéro à vos clients : sur votre site, vos réseaux et vos cartes de visite.',
            ],
            actionLabel: 'Voir mon assistant',
            actionUrl: route('channels.show', $bot),
            facts: ['Assistant' => $bot->name, 'Espace' => $workspace->name],
            greetingName: $name,
            tone: 'success',
            reason: $this->ownerReason(),
        ), urgent: true);
    }

    /* ------------------------------------------------------------------------------------------------
       Bienvenue
       ------------------------------------------------------------------------------------------------ */

    public function welcome(User $user, Workspace $workspace): void
    {
        $brand = $this->settings->brand()['name'];

        try {
            $notice = (new Notice(
                subjectLine: "Bienvenue sur {$brand}",
                heading: "Bienvenue sur {$brand}",
                paragraphs: [
                    "Votre espace « {$workspace->name} » est prêt. En une demi-heure, vous pouvez avoir un assistant qui répond à vos clients sur votre site et sur WhatsApp, à toute heure, à partir de vos propres informations.",
                    'Pour commencer : créez votre assistant, donnez-lui vos informations (documents, catalogue, site web ou simples phrases), testez-le comme un client, puis mettez-le en ligne.',
                    "Installez l'application sur votre téléphone et activez les notifications : vous serez prévenu à la seconde d'une commande ou d'un client qui attend.",
                    "Vous préférez qu'on s'en occupe ? Répondez simplement à cet e-mail : notre équipe peut tout configurer pour vous.",
                ],
                actionLabel: 'Créer mon assistant',
                actionUrl: route('bots.create'),
                facts: ['Espace' => $workspace->name],
                greetingName: $user->name,
                tone: 'success',
                reason: "Vous recevez ce message parce que vous venez de créer un compte {$brand}.",
            ));

            if ($this->capture) {
                ($this->capture)('welcome', $notice);

                return;
            }

            $this->notifier->toUser($user, 'system', "Bienvenue sur {$brand}", 'Créez votre assistant en quelques minutes : nous restons à votre disposition si vous voulez un coup de main.', route('bots.create', [], false), ['workspace_id' => $workspace->id]);
            Mail::to($user->email)->send($notice);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* ------------------------------------------------------------------------------------------------
       Équipe de la plateforme
       ------------------------------------------------------------------------------------------------ */

    public function planRequested(Workspace $workspace, string $planLabel, User $by, ?string $message = null): void
    {
        $this->toTeam("Demande d'offre : {$workspace->name}", "Offre demandée : {$planLabel}", route('admin.plan-requests.index', [], false), new Notice(
            subjectLine: "Demande d'offre : {$workspace->name}",
            heading: "Demande de changement d'offre",
            paragraphs: array_filter(["{$workspace->name} demande l'offre « {$planLabel} ».", $message ? "Son message : {$message}" : null, "Quand le paiement est reçu, enregistrez-le sur la fiche de l'espace : l'offre est activée et la demande se ferme d'elle-même."]),
            actionLabel: 'Ouvrir les demandes d\'offre',
            actionUrl: route('admin.plan-requests.index'),
            facts: ['Espace' => $workspace->name, 'Offre demandée' => $planLabel, 'Demandeur' => "{$by->name} ({$by->email})"],
            tone: 'info',
            reason: 'Vous recevez ce message parce que vous administrez la plateforme.',
            settings: false,
        ));
    }

    public function optionRequested(Workspace $workspace, string $optionLabel, User $by): void
    {
        $this->toTeam("Demande d'option : {$workspace->name}", $optionLabel, route('admin.plan-requests.index', [], false), new Notice(
            subjectLine: "Demande d'option : {$workspace->name}",
            heading: "Demande d'option à la carte",
            paragraphs: ["{$workspace->name} demande l'option « {$optionLabel} ».", "Quand le paiement est reçu, activez l'option depuis la fiche de l'espace."],
            actionLabel: 'Ouvrir les demandes',
            actionUrl: route('admin.plan-requests.index'),
            facts: ['Espace' => $workspace->name, 'Option' => $optionLabel, 'Demandeur' => "{$by->name} ({$by->email})"],
            reason: 'Vous recevez ce message parce que vous administrez la plateforme.',
            settings: false,
        ));
    }

    public function whatsappRequested(\App\Models\ChannelRequest $request, Bot $bot): void
    {
        $this->toTeam("Demande WhatsApp : {$request->business_name}", "Numéro : {$request->phone_number}", route('admin.requests.show', $request, false), new Notice(
            subjectLine: "Demande WhatsApp : {$request->business_name}",
            heading: "Nouvelle demande d'activation WhatsApp",
            paragraphs: ["{$request->business_name} demande l'activation de WhatsApp pour son assistant « {$bot->name} ».", "Ouvrez la demande pour connaître le numéro, le nom d'affichage et lancer l'activation."],
            actionLabel: 'Ouvrir la demande',
            actionUrl: route('admin.requests.show', $request),
            facts: ['Entreprise' => $request->business_name, 'Numéro' => $request->phone_number, 'Assistant' => $bot->name],
            reason: 'Vous recevez ce message parce que vous administrez la plateforme.',
            settings: false,
        ));
    }

    public function walletLow(float $balance, string $currency, float $threshold): void
    {
        $this->toTeam('Solde Twilio bas', 'Le solde est de '.number_format($balance, 2, ',', ' ')." {$currency}.", route('admin.consumption.index', [], false), new Notice(
            subjectLine: 'Solde Twilio bas',
            heading: 'Le solde Twilio de la plateforme est bas',
            paragraphs: [
                'Le solde Twilio de la plateforme est de '.number_format($balance, 2, ',', ' ')." {$currency}, sous le seuil de ".number_format($threshold, 0, ',', ' ').'.',
                "Tant qu'il n'est pas rechargé, les messages WhatsApp de tous les clients risquent de ne plus partir.",
            ],
            actionLabel: 'Ouvrir la consommation',
            actionUrl: route('admin.consumption.index'),
            tone: 'danger',
            reason: 'Vous recevez cette alerte parce que vous administrez la plateforme.',
            settings: false,
        ), urgent: true);
    }

    /* ------------------------------------------------------------------------------------------------
       Détails
       ------------------------------------------------------------------------------------------------ */

    /** Notification (centre, téléphone) à l'espace, et e-mail au propriétaire seulement. @param  callable(?string):Notice  $notice */
    private function toOwner(Workspace $workspace, string $category, string $title, string $body, string $url, callable $notice, bool $urgent = false): void
    {
        if ($this->capture) {
            ($this->capture)(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'], $notice('Awa'));

            return;
        }

        try {
            $owner = $workspace->owner();

            $this->notifier->toWorkspace($workspace, $category, $title, $body, $url, [
                'email' => $owner ? $notice($owner->name) : null,
                'urgent' => $urgent,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Alerte de l'équipe : notification aux administrateurs, et e-mail à l'adresse d'administration. */
    private function toTeam(string $title, string $body, string $url, Notice $notice, bool $urgent = false): void
    {
        if ($this->capture) {
            ($this->capture)(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'], $notice);

            return;
        }

        try {
            $this->notifier->toStaff('system', $title, $body, $url, ['urgent' => $urgent, 'tag' => 'equipe-'.md5($title), 'dedupe_minutes' => 30]);

            if ($to = $this->settings->alertEmail()) {
                Mail::to($to)->send($notice);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function ownerReason(): string
    {
        return 'Vous recevez ce message parce que vous êtes le propriétaire de cet espace.';
    }
}
