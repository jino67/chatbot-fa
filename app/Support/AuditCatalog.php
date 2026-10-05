<?php

namespace App\Support;

/**
 * Le vocabulaire du Journal : chaque action enregistrée (`AuditLog::record`) a un nom lisible, un niveau et une rubrique.
 * Une action inconnue reste lisible (son code), au niveau « normal » : ajouter une action au code ne casse jamais la page.
 */
final class AuditCatalog
{
    public const NORMAL = 'normal';

    public const SENSITIVE = 'sensible';

    public const CRITICAL = 'critique';

    /** Les rubriques du filtre : préfixes d'action. */
    public const CATEGORIES = [
        'espaces' => ['Espaces clients', ['workspace.', 'trial.', 'subscription.']],
        'paiements' => ['Paiements et portefeuille', ['payment.', 'wallet.', 'billing.']],
        'offres' => ['Offres et tarifs', ['plan.']],
        'ia' => ['IA et fournisseurs', ['ai.']],
        'equipe' => ['Équipe et comptes', ['team.', 'user.']],
        'reglages' => ['Paramètres et journal', ['settings.', 'audit.']],
        'whatsapp' => ['WhatsApp et Facebook', ['template.', 'facebook.']],
        'assistants' => ['Assistants et clés d\'API', ['bot.', 'api_key.']],
        'notifications' => ['Notifications envoyées', ['notification.']],
        'chat' => ['Supervision des conversations', ['chat.']],
    ];

    /** @var array<string,array{0:string,1:string}> action => [libellé, niveau] */
    private const ACTIONS = [
        'workspace.created' => ['Espace client créé', self::NORMAL],
        'workspace.updated' => ['Espace client modifié', self::NORMAL],
        'workspace.entered' => ['Entrée dans l\'espace d\'un client', self::SENSITIVE],
        'workspace.plan_changed' => ['Offre d\'un client changée', self::SENSITIVE],
        'workspace.addon_changed' => ['Option d\'un client changée', self::NORMAL],
        'workspace.currency' => ['Devise d\'un compte changée', self::NORMAL],
        'workspace.suspended' => ['Espace suspendu', self::CRITICAL],
        'workspace.reactivated' => ['Espace réactivé', self::SENSITIVE],
        'trial.expired' => ['Essai gratuit terminé', self::NORMAL],
        'subscription.expired' => ['Abonnement expiré', self::NORMAL],
        'payment.recorded' => ['Paiement enregistré', self::SENSITIVE],
        'wallet.topup' => ['Portefeuille rechargé', self::SENSITIVE],
        'billing.plan_requested' => ['Changement d\'offre demandé par un client', self::NORMAL],
        'plan.created' => ['Offre créée', self::SENSITIVE],
        'plan.updated' => ['Offre ou prix modifié', self::SENSITIVE],
        'plan.deleted' => ['Offre supprimée', self::CRITICAL],
        'ai.provider_added' => ['Fournisseur d\'IA ajouté', self::CRITICAL],
        'ai.provider_updated' => ['Fournisseur d\'IA modifié', self::CRITICAL],
        'ai.provider_removed' => ['Fournisseur d\'IA retiré', self::CRITICAL],
        'ai.provider_reset' => ['Fournisseur d\'IA réinitialisé', self::SENSITIVE],
        'ai.mode_changed' => ['Mode de l\'IA changé', self::SENSITIVE],
        'ai.embeddings_changed' => ['Moteur de recherche changé', self::SENSITIVE],
        'ai.reindex_started' => ['Réindexation lancée', self::NORMAL],
        'team.created' => ['Membre de l\'équipe ajouté', self::CRITICAL],
        'team.role_changed' => ['Rôle d\'un membre changé', self::CRITICAL],
        'user.created' => ['Compte utilisateur créé', self::NORMAL],
        'user.enabled' => ['Compte activé', self::SENSITIVE],
        'user.disabled' => ['Compte désactivé', self::CRITICAL],
        'user.social_linked' => ['Compte Google, Apple... relié à un compte', self::NORMAL],
        'user.social_unlinked' => ['Compte Google, Apple... retiré', self::NORMAL],
        'user.viewed' => ['Fiche d\'un utilisateur consultée', self::SENSITIVE],
        'user.contacted' => ['Utilisateur contacté par l\'équipe', self::NORMAL],
        'user.crm_updated' => ['Suivi d\'un utilisateur modifié', self::NORMAL],
        'user.bulk_contacted' => ['Envoi groupé aux inscrits', self::SENSITIVE],
        'user.exported' => ['Liste des utilisateurs exportée', self::SENSITIVE],
        'user.password_reset' => ['Mot de passe réinitialisé', self::CRITICAL],
        'settings.updated' => ['Paramètres de la plateforme modifiés', self::CRITICAL],
        'template.created' => ['Modèle WhatsApp créé', self::NORMAL],
        'template.library_created' => ['Modèles WhatsApp créés depuis la bibliothèque', self::NORMAL],
        'facebook.connected' => ['Page Facebook connectée', self::NORMAL],
        'bot.created' => ['Assistant créé', self::NORMAL],
        'bot.deleted' => ['Assistant supprimé', self::SENSITIVE],
        'bot.instructions_updated' => ['Consigne d\'un assistant modifiée', self::NORMAL],
        'bot.chat_import_started' => ['Import de discussions WhatsApp lancé', self::NORMAL],
        'bot.chat_import_done' => ['Import de discussions WhatsApp terminé', self::NORMAL],
        'api_key.created' => ['Clé d\'API créée', self::SENSITIVE],
        'api_key.revoked' => ['Clé d\'API révoquée', self::SENSITIVE],
        'notification.sent' => ['Notification envoyée aux clients', self::SENSITIVE],
        'notification.scheduled' => ['Notification programmée', self::NORMAL],
        'notification.canceled' => ['Notification annulée', self::NORMAL],
        'audit.exported' => ['Journal exporté', self::SENSITIVE],
        'chat.viewed' => ['Conversation consultée', self::SENSITIVE],
        'chat.note' => ['Note ajoutée sur une conversation', self::NORMAL],
        'chat.flagged' => ['Conversation signalée ou dé-signalée', self::NORMAL],
        'chat.reviewed' => ['Conversation marquée examinée', self::NORMAL],
        'chat.summarized' => ['Résumé IA d\'une conversation demandé', self::NORMAL],
        'chat.exported' => ['Conversations exportées', self::SENSITIVE],
    ];

    public static function label(string $action): string
    {
        return self::ACTIONS[$action][0] ?? $action;
    }

    public static function level(string $action): string
    {
        return self::ACTIONS[$action][1] ?? self::NORMAL;
    }

    /** Les actions à regarder de près : sensibles et critiques. @return list<string> */
    public static function sensitiveActions(): array
    {
        return array_keys(array_filter(self::ACTIONS, fn ($a) => $a[1] !== self::NORMAL));
    }

    /** @return list<string> les préfixes d'une rubrique (vide si elle n'existe pas) */
    public static function prefixes(?string $category): array
    {
        return self::CATEGORIES[$category][1] ?? [];
    }

    /**
     * Le détail d'une ligne en clair : « clé : valeur », sans accolades. Les listes sont jointes, les booléens traduits.
     *
     * @param  array<string,mixed>|null  $meta
     * @return list<string>
     */
    public static function details(?array $meta): array
    {
        $out = [];

        foreach ((array) $meta as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $text = match (true) {
                is_bool($value) => $value ? 'oui' : 'non',
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            };

            $out[] = str_replace('_', ' ', (string) $key).' : '.mb_strimwidth($text, 0, 120, '…');
        }

        return $out;
    }
}
