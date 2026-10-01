<?php

/*
|--------------------------------------------------------------------------
| Notifications (voir docs/NOTIFICATIONS.md et App\Notify)
|--------------------------------------------------------------------------
|
| Les catégories décrivent ce qu'une personne peut accepter ou refuser. « locked » : toujours reçue (sécurité, service).
| « urgent » : part tout de suite, même la nuit. « quiet » : respecte les heures calmes et le plafond d'un message par jour.
*/
return [

    'categories' => [
        'leads' => [
            'label' => 'Commandes, rendez-vous et devis',
            'hint' => 'Dès qu\'un client passe une commande, demande un rendez-vous ou un devis.',
            'urgent' => true,
        ],
        'handoffs' => [
            'label' => 'Un client veut parler à une personne',
            'hint' => 'Quand l\'assistant passe la main ou qu\'un client vous écrit en attendant une réponse.',
            'urgent' => true,
        ],
        'account' => [
            'label' => 'Abonnement, paiements et WhatsApp',
            'hint' => 'Échéances, paiements reçus, activation de WhatsApp, volume de messages atteint.',
        ],
        'system' => [
            'label' => 'Messages importants',
            'hint' => 'Information de service qu\'il faut connaître (panne, changement important).',
            'locked' => true,
        ],
        'promo' => [
            'label' => 'Nouveautés et offres',
            'hint' => 'Les nouveautés et les bons plans. Au plus un message par jour, jamais la nuit.',
            'quiet' => true,
        ],
    ],

    // Heures calmes (fuseau de la plateforme) : les messages « quiet » attendent le matin.
    'quiet_hours' => ['from' => 21, 'to' => 7],

    // Un seul message de la catégorie « promo » par personne et par période de ce nombre d'heures.
    'promo_cap_hours' => 20,

    'push' => [
        // Durée pendant laquelle le service de notification garde un message pour un téléphone éteint.
        'ttl' => 86400,
        // Un appareil qui échoue autant de fois de suite est oublié (désinstallé, navigateur nettoyé).
        'max_failures' => 5,
        // Seuls ces services reçoivent des messages : l'adresse d'un abonnement vient du navigateur, jamais d'une personne de confiance.
        'hosts' => [
            'fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'push.apple.com',
            'notify.windows.com', 'push.microsoft.com',
        ],
        // Envoi d'une campagne par morceaux : au plus ce nombre de personnes, dans ce nombre de secondes, à chaque passage du planificateur.
        'chunk' => 60,
        'budget_seconds' => 40,
        'timeout_seconds' => 8,
    ],

    // Rappels d'activation des notifications (côté navigateur) : combien de jours de pause après « Plus tard », et combien de refus avant d'arrêter.
    'reminder' => ['snooze_days' => 7, 'denied_snooze_days' => 14, 'max_dismissals' => 4],
];
