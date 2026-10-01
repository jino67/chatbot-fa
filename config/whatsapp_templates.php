<?php

/*
|--------------------------------------------------------------------------
| Bibliothèque de modèles de messages WhatsApp
|--------------------------------------------------------------------------
|
| Des modèles prêts à envoyer à WhatsApp pour approbation, écrits pour des petites entreprises d'Afrique francophone
| (vouvoiement, ton chaleureux, une idée par message). Voir App\Channels\WhatsApp\TemplateLibrary et docs/WHATSAPP.md.
|
| Règles de Meta respectées par chaque modèle (vérifiées par tests/Feature/WhatsAppTemplateLibraryTest) :
|   - variables numérotées {{1}}, {{2}}... dans l'ordre, jamais en première ni en dernière position du message ;
|   - un exemple par variable ('examples') : Meta l'exige pour approuver ;
|   - corps de 1024 caractères au plus, pied de message de 60, texte de bouton de 25 ;
|   - un modèle « utilitaire » parle d'une commande, d'un rendez-vous ou d'un paiement, jamais d'une promotion ;
|   - un modèle « marketing » rappelle comment ne plus recevoir d'offres.
|
| Jetons remplacés à la création : {company} (nom de l'entreprise), {site} et {phone} (un bouton qui en a besoin
| disparaît si la valeur manque). Pas de titre (« header ») : Meta le refuse avec des émojis et le rejette souvent.
*/

$optOutFr = "Répondez STOP pour ne plus recevoir d'offres";
$optOutEn = 'Reply STOP to stop receiving offers';

return [

    'groups' => [
        'relation' => 'Relation client',
        'commande' => 'Commandes et livraisons',
        'paiement' => 'Paiements',
        'rdv' => 'Rendez-vous et réservations',
        'devis' => 'Devis et interventions',
        'marketing' => 'Promotions et fidélisation',
    ],

    /*
    | Paquets : une sélection pour chaque métier. « essentiel » est inclus dans tous.
    */
    'packs' => [
        'essentiel' => [
            'label' => 'Essentiel',
            'description' => 'Accuser réception, relancer une demande, remercier, demander un avis. Convient à tous les métiers.',
            'keys' => ['message_bien_recu', 'suite_a_votre_demande', 'merci_visite', 'demande_avis'],
        ],
        'boutique' => [
            'label' => 'Boutique et commerce',
            'description' => 'Suivi de commande de A à Z, paiement reçu, retour en stock, promotions.',
            'keys' => ['commande_recue', 'commande_confirmee', 'commande_prete', 'livraison_en_route', 'commande_livree', 'commande_annulee', 'paiement_recu', 'retour_en_stock', 'promotion_du_moment', 'nouveautes_boutique'],
        ],
        'restaurant' => [
            'label' => 'Restaurant et traiteur',
            'description' => 'Réservations, commandes à emporter et livrées, invitations et promotions.',
            'keys' => ['reservation_confirmee', 'commande_recue', 'commande_prete', 'livraison_en_route', 'promotion_du_moment', 'invitation_evenement'],
        ],
        'rendez_vous' => [
            'label' => 'Rendez-vous (beauté, santé, éducation)',
            'description' => 'Confirmer, rappeler et annuler un rendez-vous ; fidéliser (anniversaire, relance).',
            'keys' => ['rdv_confirme', 'rdv_rappel', 'rdv_annule', 'anniversaire_client', 'relance_client_inactif', 'abonnement_echeance'],
        ],
        'hotel' => [
            'label' => 'Hôtel et résidence',
            'description' => 'Réservations, accueil avant le séjour, paiements, horaires exceptionnels.',
            'keys' => ['reservation_confirmee', 'reservation_sejour', 'paiement_recu', 'horaires_exceptionnels', 'promotion_du_moment'],
        ],
        'services' => [
            'label' => 'Services et artisans',
            'description' => 'Devis reçu et prêt, intervention planifiée, paiements et rappels.',
            'keys' => ['devis_recu', 'devis_pret', 'intervention_planifiee', 'paiement_recu', 'rappel_paiement', 'rdv_confirme', 'rdv_rappel'],
        ],
    ],

    /*
    | Quel paquet proposer selon le secteur de l'assistant (config/sectors.php).
    */
    'pack_by_sector' => [
        'commerce' => 'boutique',
        'restaurant' => 'restaurant',
        'sante' => 'rendez_vous',
        'beaute' => 'rendez_vous',
        'education' => 'rendez_vous',
        'hotellerie' => 'hotel',
        'immobilier' => 'services',
        'services' => 'services',
        'automobile' => 'services',
        'finance' => 'services',
        'transport' => 'services',
        'association' => 'essentiel',
        'autre' => 'essentiel',
    ],

    'templates' => [

        /* ---------- Relation client ---------- */

        'message_bien_recu' => [
            'group' => 'relation', 'category' => 'UTILITY', 'title' => 'Message bien reçu',
            'fr' => [
                'body' => "Bonjour {{1}}, merci d'avoir contacté {company} 👋\n\nNous avons bien reçu votre message et nous vous répondons dans les meilleurs délais.",
                'vars' => ['Prénom du client'], 'examples' => ['Awa'], 'footer' => '{company}',
            ],
            'en' => [
                'body' => "Hello {{1}}, thank you for contacting {company} 👋\n\nWe have received your message and will reply as soon as possible.",
                'vars' => ['Customer first name'], 'examples' => ['Awa'], 'footer' => '{company}',
            ],
        ],

        'suite_a_votre_demande' => [
            'group' => 'relation', 'category' => 'UTILITY', 'title' => 'Suite à votre demande',
            'fr' => [
                'body' => "Bonjour {{1}}, nous revenons vers vous au sujet de votre demande : {{2}}.\n\nSouhaitez-vous que nous poursuivions la conversation ici ? Répondez à ce message dès que vous êtes disponible.",
                'vars' => ['Prénom du client', 'Sujet de la demande'], 'examples' => ['Awa', 'devis pour le mariage'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Oui, poursuivons'], ['type' => 'QUICK_REPLY', 'text' => 'Plus tard']],
            ],
            'en' => [
                'body' => "Hello {{1}}, we are following up on your request: {{2}}.\n\nWould you like to continue the conversation here? Reply to this message whenever you are available.",
                'vars' => ['Customer first name', 'Request subject'], 'examples' => ['Awa', 'quote for the wedding'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => "Yes, let's continue"], ['type' => 'QUICK_REPLY', 'text' => 'Later']],
            ],
        ],

        'merci_visite' => [
            'group' => 'relation', 'category' => 'UTILITY', 'title' => 'Merci pour votre confiance',
            'fr' => [
                'body' => "Merci {{1}} pour votre confiance ! 🙏\n\nToute l'équipe de {company} reste à votre disposition : répondez simplement à ce message pour toute question.",
                'vars' => ['Prénom du client'], 'examples' => ['Awa'],
            ],
            'en' => [
                'body' => "Thank you {{1}} for your trust! 🙏\n\nThe whole {company} team remains at your disposal: simply reply to this message with any question.",
                'vars' => ['Customer first name'], 'examples' => ['Awa'],
            ],
        ],

        'demande_avis' => [
            'group' => 'relation', 'category' => 'UTILITY', 'title' => 'Demande d\'avis',
            'fr' => [
                'body' => "Bonjour {{1}}, votre avis nous aide à nous améliorer. Comment s'est passée votre expérience avec {company} ?\n\nTouchez un bouton ou écrivez-nous quelques mots.",
                'vars' => ['Prénom du client'], 'examples' => ['Awa'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Très satisfait(e)'], ['type' => 'QUICK_REPLY', 'text' => 'Satisfait(e)'], ['type' => 'QUICK_REPLY', 'text' => 'À améliorer']],
            ],
            'en' => [
                'body' => "Hello {{1}}, your feedback helps us improve. How was your experience with {company}?\n\nTap a button or write us a few words.",
                'vars' => ['Customer first name'], 'examples' => ['Awa'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Very satisfied'], ['type' => 'QUICK_REPLY', 'text' => 'Satisfied'], ['type' => 'QUICK_REPLY', 'text' => 'Could be better']],
            ],
        ],

        'horaires_exceptionnels' => [
            'group' => 'relation', 'category' => 'UTILITY', 'title' => 'Horaires exceptionnels',
            'fr' => [
                'body' => "Bonjour {{1}}, information de {company} : pendant {{2}}, nos horaires changent. Nous serons ouverts {{3}}.\n\nMerci de votre compréhension, et à bientôt !",
                'vars' => ['Prénom du client', 'Période', 'Horaires'], 'examples' => ['Awa', 'la semaine de la Tabaski', 'de 9 h à 14 h'],
            ],
        ],

        /* ---------- Commandes et livraisons ---------- */

        'commande_recue' => [
            'group' => 'commande', 'category' => 'UTILITY', 'title' => 'Commande bien reçue',
            'fr' => [
                'body' => "Bonjour {{1}}, nous avons bien reçu votre commande n° {{2}} d'un montant de {{3}} ✅\n\nNotre équipe la vérifie et vous la confirme très vite. Merci de votre confiance chez {company} !",
                'vars' => ['Prénom du client', 'Numéro de commande', 'Montant'], 'examples' => ['Awa', '1042', '18 000 FCFA'],
            ],
            'en' => [
                'body' => "Hello {{1}}, we have received your order no. {{2}} for {{3}} ✅\n\nOur team is checking it and will confirm it very soon. Thank you for trusting {company}!",
                'vars' => ['Customer first name', 'Order number', 'Amount'], 'examples' => ['Awa', '1042', '18,000 FCFA'],
            ],
        ],

        'commande_confirmee' => [
            'group' => 'commande', 'category' => 'UTILITY', 'title' => 'Commande confirmée',
            'fr' => [
                'body' => "Bonjour {{1}}, votre commande n° {{2}} est confirmée ✅\n\nMontant à régler : {{3}}\nRetrait ou livraison : {{4}}\n\nMerci d'avoir choisi {company} !",
                'vars' => ['Prénom du client', 'Numéro de commande', 'Montant', 'Retrait ou livraison'], 'examples' => ['Awa', '1042', '18 000 FCFA', 'demain avant 17 h'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => "C'est parfait"], ['type' => 'QUICK_REPLY', 'text' => 'Je dois modifier']],
            ],
            'en' => [
                'body' => "Hello {{1}}, your order no. {{2}} is confirmed ✅\n\nAmount due: {{3}}\nPickup or delivery: {{4}}\n\nThank you for choosing {company}!",
                'vars' => ['Customer first name', 'Order number', 'Amount', 'Pickup or delivery'], 'examples' => ['Awa', '1042', '18,000 FCFA', 'tomorrow before 5 pm'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Perfect'], ['type' => 'QUICK_REPLY', 'text' => 'I need to change it']],
            ],
        ],

        'commande_prete' => [
            'group' => 'commande', 'category' => 'UTILITY', 'title' => 'Commande prête à retirer',
            'fr' => [
                'body' => "Bonjour {{1}}, bonne nouvelle : votre commande n° {{2}} est prête 🎉\n\nVous pouvez venir la retirer à l'adresse suivante : {{3}}.\n\nÀ très vite chez {company} !",
                'vars' => ['Prénom du client', 'Numéro de commande', 'Adresse de retrait'], 'examples' => ['Awa', '1042', "Avenue Kwame N'Krumah, Ouagadougou"],
            ],
            'en' => [
                'body' => "Hello {{1}}, good news: your order no. {{2}} is ready 🎉\n\nYou can pick it up at the following address: {{3}}.\n\nSee you soon at {company}!",
                'vars' => ['Customer first name', 'Order number', 'Pickup address'], 'examples' => ['Awa', '1042', 'Avenue Kwame N\'Krumah, Ouagadougou'],
            ],
        ],

        'livraison_en_route' => [
            'group' => 'commande', 'category' => 'UTILITY', 'title' => 'Livraison en route',
            'fr' => [
                'body' => "Bonjour {{1}}, votre commande n° {{2}} est en route 🛵\n\nHeure d'arrivée estimée : {{3}}. Le livreur vous appellera à son arrivée : merci de rester joignable.",
                'vars' => ['Prénom du client', 'Numéro de commande', 'Heure estimée'], 'examples' => ['Awa', '1042', '15 h 30'],
                'buttons' => [['type' => 'PHONE_NUMBER', 'text' => 'Appeler la boutique', 'phone_number' => '{phone}']],
            ],
            'en' => [
                'body' => "Hello {{1}}, your order no. {{2}} is on its way 🛵\n\nEstimated arrival: {{3}}. The courier will call you on arrival, so please stay reachable.",
                'vars' => ['Customer first name', 'Order number', 'Estimated time'], 'examples' => ['Awa', '1042', '3:30 pm'],
                'buttons' => [['type' => 'PHONE_NUMBER', 'text' => 'Call the shop', 'phone_number' => '{phone}']],
            ],
        ],

        'commande_livree' => [
            'group' => 'commande', 'category' => 'UTILITY', 'title' => 'Commande livrée',
            'fr' => [
                'body' => "Bonjour {{1}}, votre commande n° {{2}} a bien été livrée 📦\n\nNous espérons qu'elle vous plaît. Un souci ? Répondez à ce message, nous nous en occupons.",
                'vars' => ['Prénom du client', 'Numéro de commande'], 'examples' => ['Awa', '1042'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Tout est parfait'], ['type' => 'QUICK_REPLY', 'text' => "J'ai un souci"]],
            ],
        ],

        'commande_annulee' => [
            'group' => 'commande', 'category' => 'UTILITY', 'title' => 'Commande annulée',
            'fr' => [
                'body' => "Bonjour {{1}}, votre commande n° {{2}} a été annulée comme convenu. Si un remboursement est prévu, notre équipe vous le confirme ici.\n\nBesoin d'aide pour passer une nouvelle commande ? Répondez à ce message.",
                'vars' => ['Prénom du client', 'Numéro de commande'], 'examples' => ['Awa', '1042'],
            ],
        ],

        /* ---------- Paiements ---------- */

        'paiement_recu' => [
            'group' => 'paiement', 'category' => 'UTILITY', 'title' => 'Paiement reçu',
            'fr' => [
                'body' => "Bonjour {{1}}, nous avons bien reçu votre paiement de {{2}} pour {{3}} ✅\n\nMerci ! Votre reçu est disponible sur simple demande : répondez à ce message.",
                'vars' => ['Prénom du client', 'Montant', 'Objet du paiement'], 'examples' => ['Awa', '18 000 FCFA', 'la commande n° 1042'],
            ],
            'en' => [
                'body' => "Hello {{1}}, we have received your payment of {{2}} for {{3}} ✅\n\nThank you! Your receipt is available on request: just reply to this message.",
                'vars' => ['Customer first name', 'Amount', 'Payment purpose'], 'examples' => ['Awa', '18,000 FCFA', 'order no. 1042'],
            ],
        ],

        'rappel_paiement' => [
            'group' => 'paiement', 'category' => 'UTILITY', 'title' => 'Rappel de paiement',
            'fr' => [
                'body' => "Bonjour {{1}}, petit rappel : le paiement de {{2}} pour {{3}} est attendu avant le {{4}}.\n\nSi c'est déjà fait, merci de ne pas tenir compte de ce message. Pour payer ou poser une question, répondez ici.",
                'vars' => ['Prénom du client', 'Montant', 'Objet du paiement', 'Date limite'], 'examples' => ['Awa', '25 000 FCFA', 'la facture de septembre', '10 octobre'],
            ],
        ],

        /* ---------- Rendez-vous et réservations ---------- */

        'rdv_confirme' => [
            'group' => 'rdv', 'category' => 'UTILITY', 'title' => 'Rendez-vous confirmé',
            'fr' => [
                'body' => "Bonjour {{1}}, votre rendez-vous chez {company} est confirmé 📅\n\nDate : {{2}}\nHeure : {{3}}\nMotif : {{4}}\n\nNous vous attendons !",
                'vars' => ['Prénom du client', 'Date', 'Heure', 'Motif'], 'examples' => ['Awa', 'mardi 14 octobre', '10 h 30', 'coupe et brushing'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => "C'est noté"], ['type' => 'QUICK_REPLY', 'text' => 'Je dois changer']],
            ],
            'en' => [
                'body' => "Hello {{1}}, your appointment at {company} is confirmed 📅\n\nDate: {{2}}\nTime: {{3}}\nReason: {{4}}\n\nWe look forward to seeing you!",
                'vars' => ['Customer first name', 'Date', 'Time', 'Reason'], 'examples' => ['Awa', 'Tuesday 14 October', '10:30 am', 'haircut'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Noted'], ['type' => 'QUICK_REPLY', 'text' => 'I need to change']],
            ],
        ],

        'rdv_rappel' => [
            'group' => 'rdv', 'category' => 'UTILITY', 'title' => 'Rappel de rendez-vous',
            'fr' => [
                'body' => "Bonjour {{1}}, rappel : vous avez rendez-vous demain à {{2}} chez {company} ({{3}}).\n\nMerci de confirmer votre venue.",
                'vars' => ['Prénom du client', 'Heure', 'Motif'], 'examples' => ['Awa', '10 h 30', 'coupe et brushing'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Je confirme'], ['type' => 'QUICK_REPLY', 'text' => 'Reporter'], ['type' => 'QUICK_REPLY', 'text' => 'Annuler']],
            ],
            'en' => [
                'body' => "Hello {{1}}, reminder: you have an appointment tomorrow at {{2}} at {company} ({{3}}).\n\nPlease confirm that you are coming.",
                'vars' => ['Customer first name', 'Time', 'Reason'], 'examples' => ['Awa', '10:30 am', 'haircut'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'I confirm'], ['type' => 'QUICK_REPLY', 'text' => 'Reschedule'], ['type' => 'QUICK_REPLY', 'text' => 'Cancel']],
            ],
        ],

        'rdv_annule' => [
            'group' => 'rdv', 'category' => 'UTILITY', 'title' => 'Rendez-vous annulé',
            'fr' => [
                'body' => "Bonjour {{1}}, votre rendez-vous du {{2}} est annulé comme convenu.\n\nSouhaitez-vous choisir un nouveau créneau ? Répondez-nous ici, nous vous proposons les prochaines disponibilités.",
                'vars' => ['Prénom du client', 'Date du rendez-vous'], 'examples' => ['Awa', 'mardi 14 octobre'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Oui, nouveau créneau'], ['type' => 'QUICK_REPLY', 'text' => 'Non merci']],
            ],
        ],

        'reservation_confirmee' => [
            'group' => 'rdv', 'category' => 'UTILITY', 'title' => 'Réservation confirmée',
            'fr' => [
                'body' => "Bonjour {{1}}, votre réservation chez {company} est confirmée 🍽️\n\nDate et heure : {{2}}\nNombre de personnes : {{3}}\n\nAu plaisir de vous accueillir !",
                'vars' => ['Prénom du client', 'Date et heure', 'Nombre de personnes'], 'examples' => ['Awa', 'samedi 18 octobre à 20 h', '4'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => "C'est noté"], ['type' => 'QUICK_REPLY', 'text' => 'Modifier']],
            ],
        ],

        'reservation_sejour' => [
            'group' => 'rdv', 'category' => 'UTILITY', 'title' => 'Votre séjour approche',
            'fr' => [
                'body' => "Bonjour {{1}}, votre séjour chez {company} approche 🏨\n\nArrivée prévue le {{2}} à partir de {{3}}.\n\nSi vous avez une demande particulière (arrivée tardive, transfert, repas), répondez à ce message.",
                'vars' => ['Prénom du client', "Date d'arrivée", "Heure d'arrivée"], 'examples' => ['Awa', '20 octobre', '14 h'],
            ],
        ],

        /* ---------- Devis et interventions ---------- */

        'devis_recu' => [
            'group' => 'devis', 'category' => 'UTILITY', 'title' => 'Demande de devis reçue',
            'fr' => [
                'body' => "Bonjour {{1}}, nous avons bien reçu votre demande de devis pour {{2}}.\n\nNotre équipe vous répond sous {{3}}. Merci de votre confiance !",
                'vars' => ['Prénom du client', 'Objet du devis', 'Délai de réponse'], 'examples' => ['Awa', 'la rénovation de la cuisine', '48 heures'],
            ],
        ],

        'devis_pret' => [
            'group' => 'devis', 'category' => 'UTILITY', 'title' => 'Devis prêt',
            'fr' => [
                'body' => "Bonjour {{1}}, votre devis pour {{2}} est prêt : {{3}}.\n\nIl est valable jusqu'au {{4}}. Répondez à ce message pour l'accepter ou poser vos questions.",
                'vars' => ['Prénom du client', 'Objet du devis', 'Montant', 'Date de validité'], 'examples' => ['Awa', 'la rénovation de la cuisine', '450 000 FCFA', '30 octobre'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => "J'accepte"], ['type' => 'QUICK_REPLY', 'text' => "J'ai une question"]],
            ],
        ],

        'intervention_planifiee' => [
            'group' => 'devis', 'category' => 'UTILITY', 'title' => 'Intervention planifiée',
            'fr' => [
                'body' => "Bonjour {{1}}, notre équipe passera le {{2}} entre {{3}} pour {{4}}.\n\nMerci de vous rendre joignable. Besoin de changer l'horaire ? Répondez à ce message.",
                'vars' => ['Prénom du client', 'Date', 'Créneau', 'Objet de la visite'], 'examples' => ['Awa', 'jeudi 16 octobre', '9 h et 11 h', 'la réparation de la climatisation'],
            ],
        ],

        'abonnement_echeance' => [
            'group' => 'devis', 'category' => 'UTILITY', 'title' => 'Abonnement bientôt échu',
            'fr' => [
                'body' => "Bonjour {{1}}, votre abonnement {{2}} arrive à échéance le {{3}}.\n\nPour le renouveler ou en savoir plus, répondez à ce message : nous vous accompagnons.",
                'vars' => ['Prénom du client', "Nom de l'abonnement", "Date d'échéance"], 'examples' => ['Awa', 'Salle de sport, formule mensuelle', '31 octobre'],
            ],
        ],

        /* ---------- Promotions et fidélisation (marketing : opt-out dans le pied de message) ---------- */

        'promotion_du_moment' => [
            'group' => 'marketing', 'category' => 'MARKETING', 'title' => 'Promotion du moment',
            'fr' => [
                'body' => "Bonjour {{1}}, cette semaine chez {company} : {{2}} 🎁\n\nOffre valable jusqu'au {{3}}. Répondez à ce message pour en profiter !",
                'vars' => ['Prénom du client', 'Offre', "Date de fin"], 'examples' => ['Awa', '-20 % sur les pagnes', 'dimanche'],
                'footer' => $optOutFr,
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => "Ça m'intéresse"], ['type' => 'QUICK_REPLY', 'text' => 'Pas maintenant']],
            ],
            'en' => [
                'body' => "Hello {{1}}, this week at {company}: {{2}} 🎁\n\nOffer valid until {{3}}. Reply to this message to take advantage of it!",
                'vars' => ['Customer first name', 'Offer', 'End date'], 'examples' => ['Awa', '20% off all fabrics', 'Sunday'],
                'footer' => $optOutEn,
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => "I'm interested"], ['type' => 'QUICK_REPLY', 'text' => 'Not now']],
            ],
        ],

        'nouveautes_boutique' => [
            'group' => 'marketing', 'category' => 'MARKETING', 'title' => 'Nouveautés',
            'fr' => [
                'body' => "Bonjour {{1}}, du nouveau chez {company} ! ✨\n\n{{2}}\n\nRépondez à ce message pour recevoir les photos et les prix.",
                'vars' => ['Prénom du client', 'Les nouveautés'], 'examples' => ['Awa', 'Une nouvelle collection de robes en wax est arrivée.'],
                'footer' => $optOutFr,
            ],
        ],

        'retour_en_stock' => [
            'group' => 'marketing', 'category' => 'MARKETING', 'title' => 'Retour en stock',
            'fr' => [
                'body' => "Bonjour {{1}}, bonne nouvelle : {{2}} est de nouveau disponible chez {company} 🙌\n\nSouhaitez-vous que nous vous le réservions ?",
                'vars' => ['Prénom du client', 'Produit'], 'examples' => ['Awa', 'la robe en wax bleue'],
                'footer' => $optOutFr,
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Oui, réservez-le'], ['type' => 'QUICK_REPLY', 'text' => 'Non merci']],
            ],
        ],

        'relance_client_inactif' => [
            'group' => 'marketing', 'category' => 'MARKETING', 'title' => 'On pense à vous',
            'fr' => [
                'body' => "Bonjour {{1}}, cela fait un moment ! {company} pense à vous 💙\n\nVoici ce que nous pouvons vous proposer : {{2}}.\n\nRépondez à ce message si cela vous intéresse.",
                'vars' => ['Prénom du client', 'Proposition'], 'examples' => ['Awa', 'une remise sur votre prochaine visite'],
                'footer' => $optOutFr,
            ],
        ],

        'anniversaire_client' => [
            'group' => 'marketing', 'category' => 'MARKETING', 'title' => 'Joyeux anniversaire',
            'fr' => [
                'body' => "Joyeux anniversaire {{1}} ! 🎂\n\nPour fêter ça, {company} vous offre {{2}}, à utiliser jusqu'au {{3}}.\n\nBelle journée à vous !",
                'vars' => ['Prénom du client', 'Cadeau', 'Date limite'], 'examples' => ['Awa', 'un soin du visage offert', '31 octobre'],
                'footer' => $optOutFr,
            ],
        ],

        'invitation_evenement' => [
            'group' => 'marketing', 'category' => 'MARKETING', 'title' => 'Invitation à un évènement',
            'fr' => [
                'body' => "Bonjour {{1}}, {company} vous invite à {{2}} le {{3}} à {{4}} 🎉\n\nRépondez à ce message pour réserver votre place.",
                'vars' => ['Prénom du client', 'Évènement', 'Date', 'Lieu'], 'examples' => ['Awa', 'notre soirée d\'ouverture', 'samedi 25 octobre', 'Ouagadougou'],
                'footer' => $optOutFr,
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Je viens'], ['type' => 'QUICK_REPLY', 'text' => 'Je ne peux pas']],
            ],
        ],
    ],
];
