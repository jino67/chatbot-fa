<?php

namespace App\Support;

use App\Models\User;

/**
 * Les messages que l'équipe envoie aux personnes inscrites, un par situation (voir App\Services\Users\UserStage). Le texte
 * est un point de départ : l'équipe le relit et le modifie avant l'envoi. Jamais de prix écrit ici (ils changent) :
 * on renvoie vers la page Abonnement de l'espace. Le courriel ajoute lui-même « Bonjour Prénom, » : le texte du courriel
 * ne commence donc pas par une salutation, celui de WhatsApp si.
 */
final class ContactTemplates
{
    /** @var array<string,array{label:string,subject:string,email:string,whatsapp:string,action:?string}> */
    private const ALL = [
        'profil' => [
            'label' => 'Terminer le profil',
            'subject' => 'Il ne manque presque rien pour démarrer',
            'email' => "Merci de vous être inscrit sur {marque}. Il ne manque que le nom de votre entreprise et votre numéro WhatsApp pour ouvrir votre espace : cela prend une minute.\n\nSi vous préférez, répondez simplement à ce message et nous le faisons ensemble.\n\n{expediteur}, équipe {marque}",
            'whatsapp' => "Bonjour {prenom}, c'est {expediteur} de {marque}. Vous vous êtes inscrit(e) mais votre profil n'est pas terminé : il manque juste le nom de votre entreprise. Je peux vous aider ?",
            'action' => 'dashboard',
        ],
        'demarrage' => [
            'label' => 'Créer son premier assistant',
            'subject' => 'Votre premier assistant en dix minutes',
            'email' => "Votre espace {entreprise} est prêt, mais vous n'avez pas encore créé d'assistant. Cela prend environ dix minutes : vous lui donnez vos prix et vos horaires (un document, une photo ou quelques phrases) et il répond à vos clients.\n\nSouhaitez-vous qu'on le fasse ensemble, par WhatsApp ou par téléphone ? Répondez simplement à ce message.\n\n{expediteur}, équipe {marque}",
            'whatsapp' => "Bonjour {prenom}, c'est {expediteur} de {marque}. Je vois que votre espace {entreprise} est prêt mais sans assistant. Voulez-vous qu'on le crée ensemble ? C'est rapide, je vous guide.",
            'action' => 'bots.create',
        ],
        'connaissances' => [
            'label' => 'Ajouter des connaissances',
            'subject' => 'Votre assistant attend vos informations',
            'email' => "Votre assistant « {assistant} » est créé, mais il ne sait encore rien : il ne répond qu'avec ce que vous lui donnez.\n\nAjoutez un document, la photo de vos prix ou quelques phrases, et il pourra répondre. Si vous préférez, envoyez-nous vos documents en répondant à ce message : nous les ajoutons pour vous.\n\n{expediteur}, équipe {marque}",
            'whatsapp' => "Bonjour {prenom}, c'est {expediteur} de {marque}. Votre assistant « {assistant} » n'a pas encore d'informations. Vous pouvez m'envoyer ici vos prix, vos horaires ou une photo de votre affiche : je les ajoute pour vous.",
            'action' => 'bots.index',
        ],
        'tester' => [
            'label' => 'Essayer son assistant',
            'subject' => 'Posez trois questions à votre assistant',
            'email' => "Votre assistant « {assistant} » a ses informations. Il ne reste qu'à l'essayer : posez-lui trois questions comme le ferait un client (prix, horaires, livraison).\n\nSi une réponse ne vous convient pas, dites-le nous : nous l'ajustons avec vous.\n\n{expediteur}, équipe {marque}",
            'whatsapp' => "Bonjour {prenom}, c'est {expediteur} de {marque}. Votre assistant « {assistant} » est prêt à être essayé : posez-lui quelques questions de clients et dites-moi ce que vous en pensez.",
            'action' => 'bots.index',
        ],
        'mise_en_ligne' => [
            'label' => 'Mettre en ligne',
            'subject' => 'Votre assistant est prêt à parler à vos clients',
            'email' => "Votre assistant « {assistant} » est prêt. Il peut maintenant répondre à vos vrais clients : sur votre site (une ligne à copier), ou avec un lien de discussion à partager sur WhatsApp et Facebook, voire un QR code à imprimer pour votre boutique.\n\nVoulez-vous que nous vous montrions comment ?\n\n{expediteur}, équipe {marque}",
            'whatsapp' => "Bonjour {prenom}, c'est {expediteur} de {marque}. Votre assistant « {assistant} » est prêt. Je peux vous montrer comment le partager à vos clients (lien, QR code, WhatsApp) ?",
            'action' => 'bots.index',
        ],
        'passer_payant' => [
            'label' => 'Passer à une offre',
            'subject' => 'Vos clients écrivent déjà à votre assistant',
            'email' => "Vos clients écrivent déjà à « {assistant} » : c'est la preuve qu'il sert. Pour continuer sans interruption, choisissez l'offre qui vous convient dans la page Abonnement de votre espace : paiement par Mobile Money ou virement, activation rapide.\n\nUne question sur les offres ? Répondez à ce message.\n\n{expediteur}, équipe {marque}",
            'whatsapp' => "Bonjour {prenom}, c'est {expediteur} de {marque}. Bonne nouvelle : vos clients écrivent à « {assistant} ». Je peux vous expliquer quelle offre vous convient le mieux ?",
            'action' => 'billing.show',
        ],
        'essai_fin' => [
            'label' => 'Essai bientôt fini',
            'subject' => 'Votre essai gratuit se termine bientôt',
            'email' => "Votre essai gratuit se termine le {date_fin} (dans {jours} jours). Pour que votre assistant continue de répondre à vos clients, choisissez une offre : paiement par Mobile Money ou virement, activation rapide.\n\nBesoin d'aide pour choisir ? Répondez à ce message.\n\n{expediteur}, équipe {marque}",
            'whatsapp' => "Bonjour {prenom}, c'est {expediteur} de {marque}. Votre essai gratuit se termine le {date_fin}. Voulez-vous que je vous aide à choisir l'offre qui convient à {entreprise} ?",
            'action' => 'billing.show',
        ],
        'essai_expire' => [
            'label' => 'Essai terminé',
            'subject' => 'Votre assistant est en pause',
            'email' => "Votre essai gratuit est terminé : votre assistant est en pause, mais tout est conservé (connaissances, conversations, réglages).\n\nChoisissez une offre et nous le remettons en service tout de suite. Si le prix ou le moment pose problème, dites-le nous : nous cherchons une solution avec vous.\n\n{expediteur}, équipe {marque}",
            'whatsapp' => "Bonjour {prenom}, c'est {expediteur} de {marque}. L'essai de {entreprise} est terminé et votre assistant est en pause. Tout est conservé : on le remet en route quand vous voulez. Je vous explique comment ?",
            'action' => 'billing.show',
        ],
        'dormant' => [
            'label' => 'Reprendre contact',
            'subject' => 'On ne vous a pas vu depuis un moment',
            'email' => "Nous ne vous avons pas vu depuis un moment. Un blocage, une question, quelque chose qui n'était pas clair ?\n\nDites-nous ce qui vous a freiné : nous pouvons vous aider à remettre votre assistant en route en quelques minutes.\n\n{expediteur}, équipe {marque}",
            'whatsapp' => "Bonjour {prenom}, c'est {expediteur} de {marque}. Ça fait un moment que je ne vous ai pas vu(e) : y a-t-il eu un blocage ? Je peux vous aider à relancer votre assistant.",
            'action' => 'dashboard',
        ],
        'merci' => [
            'label' => 'Prendre des nouvelles',
            'subject' => 'Comment se passe votre assistant ?',
            'email' => "Merci d'utiliser {marque}. Nous prenons de vos nouvelles : tout se passe comme vous le voulez ? Une idée d'amélioration, une question, un client qui n'a pas obtenu sa réponse ?\n\nRépondez simplement à ce message.\n\n{expediteur}, équipe {marque}",
            'whatsapp' => "Bonjour {prenom}, c'est {expediteur} de {marque}. Je prends de vos nouvelles : tout se passe bien avec votre assistant ? Dites-moi s'il y a quelque chose à améliorer.",
            'action' => null,
        ],
        'libre' => [
            'label' => 'Message libre',
            'subject' => '',
            'email' => '',
            'whatsapp' => 'Bonjour {prenom}, c\'est {expediteur} de {marque}. ',
            'action' => null,
        ],
    ];

    /** @return array<string,string> clé => libellé, dans l'ordre d'affichage */
    public static function labels(): array
    {
        return array_map(fn ($t) => $t['label'], self::ALL);
    }

    public static function exists(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    /**
     * Le modèle rempli pour une personne.
     *
     * @param  array{assistant?:?string, ends_at?:?\Illuminate\Support\Carbon}  $context
     * @return array{label:string, subject:string, email:string, whatsapp:string, action_label:?string, action_url:?string}
     */
    public static function render(string $key, User $customer, ?User $staff, array $context = []): array
    {
        $template = self::ALL[$key] ?? self::ALL['libre'];
        $brand = app(\App\Services\PlatformSettings::class)->brand()['name'];
        $ends = $context['ends_at'] ?? null;

        $vars = [
            '{prenom}' => trim((string) strtok((string) $customer->name, ' ')),
            '{entreprise}' => $customer->workspace?->name ?? 'votre entreprise',
            '{assistant}' => $context['assistant'] ?? 'votre assistant',
            '{expediteur}' => trim((string) strtok((string) ($staff?->name ?? $brand), ' ')),
            '{marque}' => $brand,
            '{date_fin}' => $ends ? $ends->locale('fr')->isoFormat('D MMMM') : 'bientôt',
            '{jours}' => $ends ? (string) max(0, (int) floor(now()->diffInDays($ends, false))) : 'quelques',
        ];

        $action = $template['action'];

        return [
            'label' => $template['label'],
            'subject' => strtr($template['subject'], $vars),
            'email' => strtr($template['email'], $vars),
            'whatsapp' => trim(preg_replace('/ {2,}/', ' ', str_replace(' ,', ',', strtr($template['whatsapp'], $vars)))),
            'action_label' => match ($action) {
                'billing.show' => 'Voir les offres',
                'bots.create' => 'Créer mon assistant',
                'bots.index' => 'Ouvrir mes assistants',
                'dashboard' => 'Ouvrir mon espace',
                default => null,
            },
            'action_url' => $action ? route($action) : null,
        ];
    }
}
