<?php

/*
| Pages de contenu du site public, écrites pour les recherches que font vraiment les futurs clients (« chatbot whatsapp
| pour boutique », « combien coûte un chatbot », « réponse automatique whatsapp business », « chatbot Ouagadougou »...).
| Chaque page a son titre, sa description, son adresse, un seul titre principal, des sections, des questions fréquentes
| (reprises en données structurées) et des liens vers les pages voisines.
|
| Jetons dans les textes : {brand} (nom de la marque, qui suit les réglages) et {price:slug} (prix mensuel d'une offre
| dans la devise du visiteur, FCFA par défaut). Les pages « métier » complètent leurs listes avec config/sectors.php.
| Les prix des pages de contenu sont stables (devise de la page, sinon celle de la plateforme) : les moteurs de recherche
voient toujours les mêmes chiffres, quel que soit le choix de devise d'un visiteur. Clé facultative : 'currency'.
Dates au format AAAA-MM-JJ : elles alimentent le plan du site et les données structurées.
*/

$updated = '2026-09-30';

return [

    // Regroupements affichés dans le pied de page et la page « Ressources »
    'groups' => [
        'solution' => 'Solutions',
        'guide' => 'Guides',
        'sector' => 'Par métier',
        'country' => 'Par pays',
    ],

    'pages' => [

        /* ============================== Solutions ============================== */

        'chatbot-whatsapp' => [
            'type' => 'solution', 'path' => 'chatbot-whatsapp', 'updated' => $updated,
            'label' => 'Chatbot WhatsApp',
            'title' => 'Chatbot WhatsApp pour entreprise : réponses 24 h sur 24',
            'description' => 'Un chatbot WhatsApp qui répond avec vos prix, horaires et documents, sans inventer, et vous passe la main sur demande. Essai gratuit de {trial_days} jours.',
            'h1' => 'Un chatbot WhatsApp qui répond à vos clients à votre place',
            'lead' => 'Vos clients vous écrivent sur WhatsApp à toute heure : pour un prix, un horaire, une livraison. {brand} lit vos documents et répond tout de suite, avec vos vraies informations. Quand la question dépasse le robot, il vous passe la main.',
            'sections' => [
                ['title' => 'Ce que fait votre chatbot WhatsApp', 'list' => [
                    'Il répond sur votre numéro WhatsApp avec vos prix, vos horaires, vos adresses et vos conditions de livraison.',
                    'Il comprend les messages vocaux et répond par écrit, ou en audio si vous le souhaitez.',
                    'Il prend une commande, un rendez-vous ou une demande de devis, et vous prévient tout de suite, par e-mail ou sur votre propre numéro WhatsApp.',
                    'Il vous passe la main quand un client demande une personne ou exprime un mécontentement.',
                    'Il parle plusieurs langues : français, anglais, arabe, et des langues locales avec leurs limites annoncées franchement.',
                ]],
                ['title' => 'Il ne répond qu\'avec ce que vous lui donnez', 'text' => [
                    'Vous envoyez vos documents (PDF, Word, tableaux), des photos d\'affiches ou de menus, le lien de votre site ou du texte collé. {brand} les découpe et s\'en souvient. Chaque réponse s\'appuie sur ces sources, et quand l\'information manque, l\'assistant le dit au lieu de deviner.',
                    'Vous voyez ensuite la liste des questions restées sans réponse et vous y répondez en une minute : l\'assistant s\'améliore avec votre activité.',
                ]],
                ['title' => 'Comment démarrer', 'steps' => [
                    'Créez votre assistant et envoyez vos documents, vos photos ou le lien de votre site.',
                    'Testez-le dans votre espace avec les questions de vos clients.',
                    'Activez WhatsApp : notre équipe configure le numéro avec vous (compte WhatsApp Business, vérification, branchement).',
                    'Suivez les conversations et reprenez la main quand vous le souhaitez.',
                ]],
                ['title' => 'WhatsApp : ce qu\'il faut savoir', 'text' => [
                    'WhatsApp n\'autorise le texte libre que dans les 24 heures qui suivent le dernier message du client. Au-delà, il faut un modèle de message approuvé : {brand} vous permet de les préparer et de les suivre depuis votre espace.',
                    'Les messages WhatsApp sont inclus dans votre offre, avec un volume mensuel : vous n\'avez ni compte à ouvrir chez Meta, ni carte bancaire à saisir.',
                ]],
                ['title' => 'Prix', 'text' => [
                    'L\'offre Bon plan à {price:bonplan} par mois donne un assistant sur votre site et sur WhatsApp, avec {limit:bonplan:whatsapp_messages_per_month} messages WhatsApp et {limit:bonplan:voice_per_month} messages vocaux inclus. L\'offre Pro à {price:pro} par mois ajoute plusieurs assistants, les modèles de messages et l\'import de vos discussions. Essai gratuit de {trial_days} jours, sans carte bancaire.',
                ]],
            ],
            'faq' => [
                ['Un chatbot WhatsApp peut-il inventer un prix ?', '{brand} est construit pour ne pas le faire : il ne répond qu\'à partir de vos documents. Quand l\'information manque, il le dit et propose de contacter votre équipe.'],
                ['Faut-il un numéro WhatsApp Business ?', 'Il faut un numéro que vous contrôlez et qui n\'est pas déjà enregistré sur WhatsApp. Notre équipe vous guide pour la vérification et la configuration.'],
                ['Mes clients savent-ils qu\'ils parlent à un robot ?', 'Oui : l\'assistant se présente comme l\'assistant virtuel de votre entreprise et propose de vous passer la main à tout moment.'],
                ['Puis-je répondre moi-même à un client ?', 'Oui. Dès que vous répondez depuis la boîte de réception, l\'assistant se tait pour cette conversation, et les rappels s\'arrêtent.'],
                ['Combien de messages sont inclus ?', 'Chaque offre inclut un volume mensuel de messages WhatsApp et de messages vocaux ; au-delà, vous rechargez par Mobile Money.'],
            ],
            'related' => ['reponse-automatique-whatsapp-business', 'combien-coute-un-chatbot-whatsapp', 'assistant-virtuel-site-web'],
            'cta' => ['Essayez avec vos propres documents', 'Créez votre assistant en quelques minutes et testez-le avant de l\'activer sur WhatsApp.'],
        ],

        'assistant-virtuel-site-web' => [
            'type' => 'solution', 'path' => 'assistant-virtuel-site-web', 'updated' => $updated,
            'label' => 'Assistant virtuel pour site web',
            'title' => 'Assistant virtuel pour site web : installez-le en une ligne',
            'description' => 'Ajoutez à votre site un assistant virtuel en une ligne de code : il répond avec vos informations, prend les demandes et vous passe la main.',
            'h1' => 'Un assistant virtuel pour votre site web, installé en une ligne',
            'lead' => 'Vos visiteurs posent toujours les mêmes questions : prix, horaires, livraison, rendez-vous. Avec {brand}, une bulle de discussion répond à la place de votre équipe, avec vos propres informations, et laisse un moyen de vous joindre quand il le faut.',
            'sections' => [
                ['title' => 'Une ligne de code, sur n\'importe quel site', 'text' => [
                    'Vous copiez une ligne dans votre site (WordPress, Wix, Shopify ou site sur mesure : tout site qui accepte un extrait de code). Le widget s\'affiche dans une zone isolée : il n\'abîme pas votre mise en page, et votre mise en page ne l\'abîme pas. Vous choisissez sa couleur, sa position, son titre et les domaines autorisés.',
                ]],
                ['title' => 'Ce que vos visiteurs peuvent faire', 'list' => [
                    'Poser leur question par écrit, ou la dicter avec le micro.',
                    'Écouter une réponse, la copier, dire si elle était utile.',
                    'Choisir leur langue parmi celles que parle votre assistant.',
                    'Démarrer une nouvelle conversation, ou continuer sur WhatsApp en un geste.',
                    'Demander à parler à une personne : vous êtes prévenu tout de suite.',
                ]],
                ['title' => 'Ce que vous gagnez', 'list' => [
                    'Moins de questions répétitives, et des réponses à toute heure, y compris la nuit et le week-end.',
                    'La liste des questions sans réponse : ce que vos visiteurs cherchent et que votre site ne dit pas encore.',
                    'Les demandes de commande, de rendez-vous et de devis regroupées au même endroit.',
                ]],
                ['title' => 'Sans site web ?', 'text' => [
                    'Vous pouvez aussi partager une page de démonstration par lien, ou publier votre assistant sur WhatsApp. Le site web n\'est pas obligatoire.',
                ]],
            ],
            'faq' => [
                ['Le widget ralentit-il mon site ?', 'Non : il se charge après votre page, sans dépendance, et reste isolé du reste du site.'],
                ['Puis-je limiter les sites où il fonctionne ?', 'Oui : une liste blanche de domaines protège votre assistant contre l\'usage depuis un site tiers.'],
                ['Les visiteurs doivent-ils donner leur nom ?', 'Seulement si vous le souhaitez : vous pouvez demander un nom et un téléphone avant la discussion.'],
                ['Puis-je retirer la mention « Propulsé par » ?', 'Oui, avec l\'offre Pro ou Business.'],
            ],
            'related' => ['chatbot-whatsapp', 'chatbot-ia-sans-inventer-de-prix', 'creer-un-chatbot-whatsapp-pour-son-entreprise'],
            'cta' => ['Installez-le sur votre site aujourd\'hui', 'Créez votre assistant, copiez la ligne de code, et c\'est en ligne.'],
        ],

        'reponse-automatique-whatsapp-business' => [
            'type' => 'solution', 'path' => 'reponse-automatique-whatsapp-business', 'updated' => $updated,
            'label' => 'Réponse automatique WhatsApp Business',
            'title' => 'Réponse automatique WhatsApp Business : quelle solution ?',
            'description' => 'Message d\'accueil, d\'absence, réponses rapides ou chatbot : ce que permet WhatsApp Business, ses limites, et quand passer à un assistant qui répond vraiment.',
            'h1' => 'Réponse automatique sur WhatsApp Business : que choisir ?',
            'lead' => 'Vous voulez que vos clients reçoivent une réponse même quand vous ne pouvez pas écrire. L\'application WhatsApp Business offre déjà quelques outils ; un assistant va plus loin. Voici la différence, sans détour.',
            'sections' => [
                ['title' => 'Ce que propose l\'application WhatsApp Business', 'list' => [
                    'Un message d\'accueil, envoyé quand un client vous écrit pour la première fois ou après une période sans échange.',
                    'Un message d\'absence, envoyé en dehors de vos horaires.',
                    'Des réponses rapides : des phrases enregistrées que vous insérez en tapant un raccourci.',
                    'Des étiquettes pour classer vos conversations, à poser à la main.',
                ]],
                ['title' => 'Leurs limites', 'text' => [
                    'Ces messages sont toujours les mêmes : ils ne répondent pas à la question posée. Un client qui demande « vous livrez à Bobo ? » reçoit le même message d\'accueil que celui qui demande un horaire, et doit attendre votre réponse.',
                ]],
                ['title' => 'Ce qu\'apporte un assistant', 'list' => [
                    'Il répond à la question posée, avec vos prix et vos conditions, tout de suite.',
                    'Il prend les commandes et les rendez-vous, et vous prévient.',
                    'Il vous passe la main quand c\'est nécessaire, avec la conversation déjà résumée.',
                    'Il garde la trace de ce qu\'on lui demande et que vous n\'avez pas encore écrit quelque part.',
                ]],
                ['title' => 'Les deux se complètent', 'text' => [
                    'Vous pouvez garder vos messages d\'accueil et vos étiquettes dans l\'application, et confier les réponses à l\'assistant. {brand} indique pour chaque demande l\'étiquette à poser à la main si vous utilisez aussi l\'application (WhatsApp ne permet pas de les poser automatiquement).',
                ]],
            ],
            'faq' => [
                ['Le message d\'absence suffit-il ?', 'Il rassure le client mais ne répond pas à sa question. Pour un commerce qui reçoit beaucoup de demandes, un assistant fait gagner plus de temps.'],
                ['Un assistant peut-il envoyer un message d\'accueil ?', 'Oui : il accueille chaque client, se présente et propose des réponses rapides adaptées à votre métier.'],
                ['Puis-je reprendre la conversation ?', 'Oui, à tout moment, depuis la boîte de réception ou votre téléphone si vous utilisez l\'application.'],
            ],
            'related' => ['chatbot-whatsapp', 'whatsapp-business-application-ou-api', 'combien-coute-un-chatbot-whatsapp'],
            'cta' => ['Passez de la réponse automatique à la vraie réponse', 'Essayez gratuitement pendant {trial_days} jours.'],
        ],

        /* ============================== Guides ============================== */

        'combien-coute-un-chatbot-whatsapp' => [
            'type' => 'guide', 'path' => 'guides/combien-coute-un-chatbot-whatsapp', 'updated' => $updated,
            'label' => 'Combien coûte un chatbot WhatsApp ?',
            'title' => 'Combien coûte un chatbot WhatsApp ? Prix et frais cachés',
            'description' => 'Abonnement, messages WhatsApp, intelligence artificielle, installation : ce qui fait le prix d\'un chatbot WhatsApp, les frais à surveiller et des prix en FCFA.',
            'h1' => 'Combien coûte un chatbot WhatsApp pour une petite entreprise ?',
            'lead' => 'Le prix d\'un chatbot WhatsApp se compose de plusieurs postes, et tous ne sont pas toujours annoncés. Voici comment les repérer, ce qui les fait varier, et combien coûte {brand}.',
            'sections' => [
                ['title' => 'Les quatre postes à regarder', 'steps' => [
                    'L\'abonnement à la plateforme : c\'est le prix mensuel de l\'outil, avec ses limites (nombre d\'assistants, de réponses, de sources).',
                    'Les messages WhatsApp : Meta facture les messages selon leur catégorie (marketing, utilitaire, authentification, service) et le pays. Une plateforme peut les inclure dans son prix, ou les facturer à part.',
                    'L\'intelligence artificielle : chaque réponse générée a un coût. Une offre honnête fixe un nombre de réponses par mois.',
                    'La mise en route : configuration du numéro, vérification de l\'entreprise, rédaction des consignes. Certains prestataires la facturent, d\'autres l\'incluent.',
                ]],
                ['title' => 'Les frais qu\'on découvre trop tard', 'list' => [
                    'Des messages WhatsApp facturés sur votre propre carte bancaire, à payer en plus de l\'abonnement.',
                    'Un nombre de conversations limité sans dire ce qui se passe au-delà.',
                    'Des fonctions essentielles (passage à un humain, plusieurs langues, statistiques) réservées à l\'offre la plus chère.',
                    'Un prix en dollars ou en euros, sans possibilité de payer par Mobile Money.',
                ]],
                ['title' => 'Les prix de {brand}', 'text' => [
                    'Les messages WhatsApp sont inclus dans chaque offre qui comprend WhatsApp, avec un volume mensuel. Vous payez par Orange Money, Moov Money, Coris Money ou virement, en FCFA, en franc comorien, en euro, en dollar ou en dirham.',
                ], 'list' => [
                    'Essai gratuit : {trial_days} jours, {limit:free:messages_per_month} réponses, sans carte bancaire.',
                    'Essentiel, {price:essentiel} par mois : un assistant sur votre site web, {limit:essentiel:messages_per_month} réponses par mois.',
                    'Bon plan, {price:bonplan} par mois : un assistant sur votre site et sur WhatsApp, {limit:bonplan:messages_per_month} réponses, {limit:bonplan:whatsapp_messages_per_month} messages WhatsApp et {limit:bonplan:voice_per_month} messages vocaux par mois.',
                    'Pro, {price:pro} par mois : {limit:pro:bots} assistants, {limit:pro:messages_per_month} réponses, {limit:pro:whatsapp_messages_per_month} messages WhatsApp, modèles de messages, import de vos discussions.',
                    'Business, {price:business} par mois : {limit:business:bots} assistants, {limit:business:messages_per_month} réponses, {limit:business:whatsapp_messages_per_month} messages WhatsApp, assistance prioritaire.',
                ]],
                ['title' => 'Comment choisir', 'text' => [
                    'Comptez vos conversations : combien de clients vous écrivent par jour, et combien de messages chacun envoie en moyenne ? Une petite boutique reçoit souvent une dizaine de conversations par jour : le Bon plan suffit. Un commerce très actif, ou plusieurs points de vente, choisira Pro.',
                    'Le dépassement n\'est pas une mauvaise surprise : vous voyez votre consommation dans votre espace et vous rechargez des messages par Mobile Money si besoin.',
                ]],
            ],
            'faq' => [
                ['Les messages WhatsApp sont-ils facturés en plus ?', 'Chez {brand}, non : un volume mensuel est inclus dans chaque offre avec WhatsApp, et vous ne saisissez aucune carte bancaire.'],
                ['Peut-on payer par Mobile Money ?', 'Oui : Orange Money, Moov Money, Coris Money, ou virement. Vous envoyez la référence du paiement et l\'offre est activée.'],
                ['Y a-t-il un engagement ?', 'Non : vous changez d\'offre ou vous arrêtez quand vous voulez.'],
                ['Existe-t-il une offre gratuite ?', 'Un essai de {trial_days} jours, sans carte bancaire, limité à {limit:free:messages_per_month} réponses. Passé ce délai, l\'assistant se met en pause et vos données sont conservées.'],
            ],
            'related' => ['creer-un-chatbot-whatsapp-pour-son-entreprise', 'chatbot-whatsapp', 'whatsapp-business-application-ou-api'],
            'cta' => ['Testez sans carte bancaire', '{trial_days} jours d\'essai, vos propres documents, aucun engagement.'],
        ],

        'creer-un-chatbot-whatsapp-pour-son-entreprise' => [
            'type' => 'guide', 'path' => 'guides/creer-un-chatbot-whatsapp-pour-son-entreprise', 'updated' => $updated,
            'label' => 'Créer un chatbot WhatsApp pour son entreprise',
            'title' => 'Créer un chatbot WhatsApp pour son entreprise en 6 étapes',
            'description' => 'Lister les questions, rassembler vos documents, tester, prévoir le passage à une personne, activer WhatsApp et mesurer : le guide pratique pour une PME.',
            'h1' => 'Créer un chatbot WhatsApp pour son entreprise : le guide en 6 étapes',
            'lead' => 'Un bon chatbot ne demande pas de savoir programmer : il demande de savoir ce que vos clients vous demandent. Suivez ces six étapes, dans l\'ordre, pour en avoir un qui sert vraiment.',
            'sections' => [
                ['title' => 'Les six étapes', 'steps' => [
                    'Listez les dix questions que vos clients posent le plus souvent (prix, horaires, livraison, paiement, disponibilité, adresse). Relisez vos conversations WhatsApp récentes pour ne rien oublier.',
                    'Rassemblez les documents qui y répondent : tarifs, catalogue, conditions de livraison, menus, plan d\'accès. Des photos d\'affiches suffisent. Si une information n\'est écrite nulle part, écrivez-la : le chatbot ne peut pas la deviner.',
                    'Créez l\'assistant, donnez-lui ses sources et choisissez son ton (tutoiement ou vouvoiement, émojis, longueur des réponses).',
                    'Testez avec de vraies questions, y compris les plus tordues. Vérifiez qu\'il dit « je ne sais pas » quand il ne sait pas, plutôt que d\'inventer.',
                    'Prévoyez le passage à une personne : qui est prévenu, comment (e-mail, WhatsApp), et en combien de temps il doit répondre.',
                    'Activez WhatsApp, annoncez-le à vos clients, puis regardez chaque semaine les questions restées sans réponse pour compléter vos documents.',
                ]],
                ['title' => 'Les erreurs fréquentes', 'list' => [
                    'Vouloir tout automatiser dès le premier jour : commencez par les questions répétitives.',
                    'Donner des documents périmés : un ancien tarif reste dans la mémoire de l\'assistant tant que vous ne le retirez pas.',
                    'Oublier de dire au chatbot quand passer la main : un client mécontent doit parler à une personne.',
                    'Ne jamais relire les conversations : c\'est là que vous voyez ce qui manque.',
                ]],
                ['title' => 'Combien de temps faut-il ?', 'text' => [
                    'Avec des documents prêts, comptez une heure pour créer et tester un premier assistant. L\'activation de WhatsApp dépend de la vérification de votre numéro : notre équipe vous accompagne.',
                ]],
            ],
            'faq' => [
                ['Faut-il savoir programmer ?', 'Non : vous envoyez vos documents et vous choisissez des réglages. Aucun code, sauf la ligne à copier pour le site web.'],
                ['Quels documents accepte-t-il ?', 'PDF, Word, tableaux, texte, photos (lues par intelligence artificielle), liens de sites web et contenu de pages Facebook.'],
                ['Puis-je corriger une réponse ?', 'Oui : dans la liste des questions sans réponse, vous écrivez la bonne réponse et elle est retenue aussitôt.'],
            ],
            'related' => ['combien-coute-un-chatbot-whatsapp', 'chatbot-ia-sans-inventer-de-prix', 'chatbot-whatsapp'],
            'cta' => ['Commencez maintenant', 'Créez votre assistant et envoyez vos premiers documents.'],
        ],

        'whatsapp-business-application-ou-api' => [
            'type' => 'guide', 'path' => 'guides/whatsapp-business-application-ou-api', 'updated' => $updated,
            'label' => 'WhatsApp Business : application ou API ?',
            'title' => 'WhatsApp Business : application ou API, que choisir ?',
            'description' => 'L\'application WhatsApp Business et l\'API WhatsApp Cloud ne servent pas au même usage. Ce que chacune permet, leurs limites, et laquelle choisir pour un chatbot.',
            'h1' => 'WhatsApp Business : application ou API, laquelle choisir ?',
            'lead' => 'Ces deux mots prêtent à confusion. L\'application est celle que vous installez sur votre téléphone ; l\'API est la porte d\'entrée des logiciels, donc des chatbots. Voici ce qui les distingue.',
            'sections' => [
                ['title' => 'L\'application WhatsApp Business', 'list' => [
                    'Gratuite, installée sur un téléphone, avec un profil d\'entreprise, un catalogue, des étiquettes, des réponses rapides et des messages automatiques simples.',
                    'Parfaite pour démarrer et pour les petites équipes qui répondent à la main.',
                    'Limitée : pas de chatbot qui comprend la question, peu de personnes qui peuvent répondre en même temps, pas de statistiques poussées.',
                ]],
                ['title' => 'L\'API WhatsApp Cloud', 'list' => [
                    'Proposée par Meta aux logiciels : c\'est par elle qu\'un assistant lit et envoie des messages.',
                    'Permet l\'automatisation, plusieurs personnes sur la même ligne, des statistiques et des modèles de messages approuvés.',
                    'Les messages sont facturés par Meta selon leur catégorie. Avec {brand}, ce coût est inclus dans votre offre.',
                    'Les étiquettes de l\'application ne sont pas disponibles par l\'API : une plateforme doit proposer les siennes.',
                ]],
                ['title' => 'Laquelle choisir ?', 'text' => [
                    'Si vous répondez à quelques clients par jour, l\'application suffit. Dès que les mêmes questions reviennent, que vous voulez répondre la nuit, ou que plusieurs personnes doivent intervenir, passez par l\'API avec un assistant.',
                    'Le numéro utilisé par l\'API ne peut pas être utilisé en même temps dans l\'application WhatsApp classique, sauf mode de coexistence proposé par Meta sous conditions. Notre équipe vous explique la marche à suivre pour votre numéro.',
                ]],
                ['title' => 'La fenêtre de 24 heures', 'text' => [
                    'Par l\'API, vous pouvez répondre librement dans les 24 heures qui suivent le dernier message du client. Ensuite, seul un modèle de message approuvé par WhatsApp peut rouvrir la conversation.',
                ]],
            ],
            'faq' => [
                ['Mon numéro actuel peut-il passer sur l\'API ?', 'Souvent oui, mais il ne doit pas être enregistré dans l\'application WhatsApp classique au même moment. Notre équipe étudie votre cas.'],
                ['Perd-on ses anciennes conversations ?', 'Les conversations de l\'application ne sont pas transférées automatiquement : prévoyez une exportation si elles comptent. {brand} peut apprendre de votre style à partir d\'un export.'],
                ['L\'API est-elle payante ?', 'Meta facture les messages envoyés selon leur catégorie et le pays. Chez {brand}, un volume mensuel est inclus dans l\'offre.'],
            ],
            'related' => ['reponse-automatique-whatsapp-business', 'chatbot-whatsapp', 'combien-coute-un-chatbot-whatsapp'],
            'cta' => ['Faites-vous accompagner pour le numéro', 'Notre équipe configure WhatsApp avec vous.'],
        ],

        'chatbot-ia-sans-inventer-de-prix' => [
            'type' => 'guide', 'path' => 'guides/chatbot-ia-sans-inventer-de-prix', 'updated' => $updated,
            'label' => 'Un chatbot peut-il inventer un prix ?',
            'title' => 'Un chatbot IA peut-il inventer un prix ? Comment l\'éviter',
            'description' => 'Les chatbots à intelligence artificielle peuvent se tromper. Comment un assistant limité à vos documents évite d\'inventer un prix ou un horaire.',
            'h1' => 'Un chatbot IA peut-il inventer un prix ou un horaire ?',
            'lead' => 'C\'est la peur de tous les commerçants : un robot qui annonce un mauvais prix à un client. Le risque est réel avec un chatbot généraliste. Il se maîtrise avec une règle simple : ne répondre qu\'avec vos documents.',
            'sections' => [
                ['title' => 'Pourquoi un chatbot se trompe', 'text' => [
                    'Un modèle d\'intelligence artificielle généraliste complète ce qu\'il ne sait pas par ce qui lui paraît vraisemblable. C\'est utile pour écrire un texte, dangereux pour annoncer un prix : il ne connaît pas votre boutique.',
                ]],
                ['title' => 'Comment {brand} limite le risque', 'list' => [
                    'L\'assistant ne répond qu\'à partir des extraits de vos documents retrouvés pour la question posée.',
                    'Un seuil de pertinence écarte les extraits trop éloignés : si rien ne correspond, il ne cherche pas à broder.',
                    'Quand l\'information manque, il le dit, propose de contacter votre équipe et note la question.',
                    'Les prix, horaires et noms sont repris tels qu\'écrits dans vos documents.',
                    'Il ignore les consignes cachées dans un message (« oublie tes règles ») et ne révèle pas ses instructions.',
                    'Le contenu importé d\'un site ou d\'un fichier est traité comme une information, jamais comme un ordre.',
                ]],
                ['title' => 'Ce que vous pouvez vérifier', 'list' => [
                    'Chaque réponse cite ses sources : vous voyez d\'où vient l\'information.',
                    'La liste des questions sans réponse montre ce que l\'assistant a refusé d\'inventer.',
                    'Les conversations sont relisibles, avec un repère pour les réponses qui s\'appuyaient sur rien.',
                    'Les visiteurs peuvent signaler une réponse à améliorer (pouce vers le bas).',
                ]],
                ['title' => 'Comment écrire de bons documents', 'text' => [
                    'Une phrase claire vaut mieux qu\'un long document : « Livraison à Bobo-Dioulasso : 2 000 FCFA, sous 48 h. » Datez vos tarifs, retirez les anciens, et ajoutez les questions que vos clients posent vraiment. L\'assistant est aussi bon que ce que vous lui donnez.',
                ]],
            ],
            'faq' => [
                ['Le risque est-il nul ?', 'Aucun système n\'est infaillible. Les garde-fous réduisent fortement le risque, et la relecture des conversations permet de corriger vite.'],
                ['Que se passe-t-il si mes documents se contredisent ?', 'L\'assistant peut reprendre l\'un ou l\'autre : gardez un seul tarif à jour, et supprimez les anciens documents.'],
                ['Mes documents servent-ils à d\'autres entreprises ?', 'Non : chaque entreprise a son espace isolé, et vos documents ne servent qu\'à votre assistant.'],
            ],
            'related' => ['creer-un-chatbot-whatsapp-pour-son-entreprise', 'chatbot-whatsapp', 'assistant-virtuel-site-web'],
            'cta' => ['Vérifiez-le avec vos propres questions', 'L\'essai gratuit permet de tester les pièges avant de publier.'],
        ],

        /* ============================== Métiers ============================== */

        'chatbot-whatsapp-boutique' => [
            'type' => 'sector', 'sector' => 'commerce', 'path' => 'chatbot-whatsapp-boutique', 'updated' => $updated,
            'label' => 'Boutique et commerce',
            'title' => 'Chatbot WhatsApp pour boutique : prix, stock, livraison',
            'description' => 'Un assistant qui répond aux clients de votre boutique sur WhatsApp et sur votre site : prix, tailles, livraison, paiement, et prise de commande que vous confirmez.',
            'h1' => 'Un chatbot WhatsApp pour votre boutique',
            'lead' => 'Dans un commerce, la même question revient cent fois : « c\'est combien ? », « vous livrez ? », « il en reste ? ». L\'assistant y répond avec vos prix, prend la commande et vous laisse la confirmer.',
            'dialog' => [['Client', 'Vous livrez à Bobo ?'], ['Assistant', 'Oui, à Bobo-Dioulasso : 2 000 FCFA, sous 48 h. Gratuit dès 25 000 FCFA d\'achat. Voulez-vous passer commande ?']],
            'faq' => [
                ['L\'assistant peut-il dire si un article est en stock ?', 'Seulement si votre document le dit. Sinon il propose de vérifier avec votre équipe, sans promettre.'],
                ['Qui confirme les commandes ?', 'Vous : l\'assistant prépare la commande, vous prévient, et vous la confirmez depuis la page Demandes.'],
            ],
            'related' => ['chatbot-whatsapp', 'combien-coute-un-chatbot-whatsapp', 'chatbot-whatsapp-salon-de-beaute'],
        ],

        'chatbot-whatsapp-restaurant' => [
            'type' => 'sector', 'sector' => 'restaurant', 'path' => 'chatbot-whatsapp-restaurant', 'updated' => $updated,
            'label' => 'Restaurant et traiteur',
            'title' => 'Chatbot WhatsApp pour restaurant : menu et réservations',
            'description' => 'Menu, prix, horaires, livraison et réservation : un assistant qui répond aux clients de votre restaurant ou de votre traiteur sur WhatsApp, à toute heure.',
            'h1' => 'Un chatbot WhatsApp pour votre restaurant',
            'lead' => 'Le midi et le soir, vous n\'avez pas le temps de répondre. L\'assistant donne le menu, les horaires, les prix et la livraison, prend la réservation ou la commande, et vous prévient.',
            'dialog' => [['Client', 'Vous êtes ouverts dimanche soir ?'], ['Assistant', 'Oui, le dimanche nous sommes ouverts de 18 h à 23 h. Voulez-vous réserver une table ? Dites-moi le nombre de personnes et l\'heure.']],
            'faq' => [
                ['Peut-il lire mon menu en photo ?', 'Oui : une photo de votre carte ou de votre affiche est lue par intelligence artificielle, puis retenue.'],
                ['Gère-t-il les allergies ?', 'Il ne répond que si votre document le précise, et vous passe la main sur les questions de santé.'],
            ],
            'related' => ['chatbot-whatsapp', 'chatbot-whatsapp-hotel', 'assistant-virtuel-site-web'],
        ],

        'chatbot-whatsapp-clinique' => [
            'type' => 'sector', 'sector' => 'sante', 'path' => 'chatbot-whatsapp-clinique', 'updated' => $updated,
            'label' => 'Clinique, cabinet, pharmacie',
            'title' => 'Chatbot WhatsApp pour clinique et cabinet médical',
            'description' => 'Horaires, tarifs, rendez-vous : un assistant pour clinique ou cabinet qui répond aux patients sans donner d\'avis médical et passe la main au personnel.',
            'h1' => 'Un chatbot WhatsApp pour clinique et cabinet médical',
            'lead' => 'L\'accueil téléphonique est saturé. L\'assistant répond aux questions pratiques (horaires, tarifs, spécialités, accès) et prépare les rendez-vous, sans jamais donner de conseil médical.',
            'dialog' => [['Patient', 'Quels sont les horaires du cabinet ?'], ['Assistant', 'Le cabinet est ouvert du lundi au vendredi, de 8 h à 17 h. Souhaitez-vous demander un rendez-vous ?']],
            'faq' => [
                ['L\'assistant donne-t-il des conseils médicaux ?', 'Non : il l\'indique clairement et oriente vers un professionnel. En cas d\'urgence, il passe la main tout de suite.'],
                ['Les données des patients sont-elles protégées ?', 'Chaque entreprise a son espace isolé et l\'assistant n\'a accès à rien d\'autre que vos documents. Informez vos patients de l\'usage d\'un assistant.'],
            ],
            'related' => ['chatbot-whatsapp', 'chatbot-ia-sans-inventer-de-prix', 'chatbot-whatsapp-salon-de-beaute'],
        ],

        'chatbot-whatsapp-ecole' => [
            'type' => 'sector', 'sector' => 'education', 'path' => 'chatbot-whatsapp-ecole', 'updated' => $updated,
            'label' => 'École et formation',
            'title' => 'Chatbot WhatsApp pour école et centre de formation',
            'description' => 'Inscriptions, frais de scolarité, calendrier, filières : un assistant qui répond aux parents et aux étudiants de votre établissement sur WhatsApp et sur votre site.',
            'h1' => 'Un chatbot WhatsApp pour votre école ou votre centre de formation',
            'lead' => 'À la rentrée, les mêmes questions arrivent par centaines : dates d\'inscription, frais, pièces à fournir. L\'assistant répond à toute heure et transmet les demandes à votre secrétariat.',
            'dialog' => [['Parent', 'Quelles pièces faut-il pour inscrire mon fils ?'], ['Assistant', 'Il faut l\'acte de naissance, le bulletin de l\'an dernier et deux photos d\'identité. Souhaitez-vous connaître les frais de scolarité ?']],
            'faq' => [
                ['Peut-il donner les résultats d\'un élève ?', 'Non : il ne répond qu\'avec des informations générales de vos documents et passe la main pour les dossiers personnels.'],
            ],
            'related' => ['chatbot-whatsapp', 'assistant-virtuel-site-web', 'creer-un-chatbot-whatsapp-pour-son-entreprise'],
        ],

        'chatbot-whatsapp-immobilier' => [
            'type' => 'sector', 'sector' => 'immobilier', 'path' => 'chatbot-whatsapp-immobilier', 'updated' => $updated,
            'label' => 'Agence immobilière',
            'title' => 'Chatbot WhatsApp pour agence immobilière : visites et biens',
            'description' => 'Présentez vos biens, répondez aux questions sur les prix et les conditions, et récupérez les demandes de visite : un assistant pour agence immobilière sur WhatsApp.',
            'h1' => 'Un chatbot WhatsApp pour votre agence immobilière',
            'lead' => 'Un acheteur écrit à 21 h pour savoir si l\'appartement est toujours disponible. L\'assistant répond avec vos annonces, recueille ses coordonnées et vous passe la demande de visite.',
            'dialog' => [['Client', 'La villa de Ouaga 2000 est encore disponible ?'], ['Assistant', 'Selon notre annonce, elle est proposée à 65 000 000 FCFA. Voulez-vous visiter ? Donnez-moi vos disponibilités et un numéro pour vous rappeler.']],
            'faq' => [
                ['Met-il à jour les annonces lui-même ?', 'Vous mettez à jour vos documents ou votre site : pour un site web, une mise à jour automatique relit vos pages régulièrement.'],
            ],
            'related' => ['chatbot-whatsapp', 'assistant-virtuel-site-web', 'chatbot-whatsapp-services'],
        ],

        'chatbot-whatsapp-hotel' => [
            'type' => 'sector', 'sector' => 'hotellerie', 'path' => 'chatbot-whatsapp-hotel', 'updated' => $updated,
            'label' => 'Hôtel et résidence',
            'title' => 'Chatbot WhatsApp pour hôtel et résidence meublée',
            'description' => 'Chambres, tarifs, services, accès : un assistant qui répond aux voyageurs de votre hôtel sur WhatsApp, en français et en anglais, et prépare les réservations.',
            'h1' => 'Un chatbot WhatsApp pour votre hôtel ou votre résidence',
            'lead' => 'Les voyageurs écrivent depuis un autre fuseau horaire et veulent une réponse tout de suite. L\'assistant donne les tarifs, les services et l\'accès, en français et en anglais, et prépare la réservation.',
            'dialog' => [['Voyageur', 'Do you have a room for two nights from Friday?'], ['Assistant', 'Yes, we offer double rooms from 35 000 FCFA per night. Would you like me to send your request to our reception team?']],
            'faq' => [
                ['Répond-il en anglais ?', 'Oui : il répond dans la langue du client, parmi celles que vous avez choisies pour votre assistant.'],
            ],
            'related' => ['chatbot-whatsapp', 'chatbot-whatsapp-restaurant', 'assistant-virtuel-site-web'],
        ],

        'chatbot-whatsapp-salon-de-beaute' => [
            'type' => 'sector', 'sector' => 'beaute', 'path' => 'chatbot-whatsapp-salon-de-beaute', 'updated' => $updated,
            'label' => 'Salon de beauté et coiffure',
            'title' => 'Chatbot WhatsApp pour salon de coiffure et de beauté',
            'description' => 'Tarifs des prestations, horaires, rendez-vous : un assistant pour salon de coiffure ou de beauté qui répond sur WhatsApp pendant que vous avez les mains occupées.',
            'h1' => 'Un chatbot WhatsApp pour votre salon de coiffure ou de beauté',
            'lead' => 'Quand vous coiffez, vous ne pouvez pas répondre. L\'assistant donne vos tarifs et vos créneaux, et note les demandes de rendez-vous que vous confirmez entre deux clientes.',
            'dialog' => [['Cliente', 'C\'est combien les tresses ?'], ['Assistant', 'Les tresses commencent à 8 000 FCFA selon le modèle. Voulez-vous prendre rendez-vous ? Indiquez le jour et l\'heure souhaités.']],
            'faq' => [
                ['Qui confirme le rendez-vous ?', 'Vous : la demande arrive dans votre page Demandes avec les coordonnées de la cliente, et vous lui répondez depuis WhatsApp.'],
            ],
            'related' => ['chatbot-whatsapp', 'chatbot-whatsapp-boutique', 'reponse-automatique-whatsapp-business'],
        ],

        'chatbot-whatsapp-services' => [
            'type' => 'sector', 'sector' => 'services', 'path' => 'chatbot-whatsapp-services', 'updated' => $updated,
            'label' => 'Prestataires de services',
            'title' => 'Chatbot WhatsApp pour prestataires de services : devis',
            'description' => 'Artisans, cabinets, agences, dépanneurs : un assistant qui présente vos services, répond aux questions fréquentes et recueille les demandes de devis sur WhatsApp.',
            'h1' => 'Un chatbot WhatsApp pour votre activité de services',
            'lead' => 'Plombier, consultant, agence de communication : vos clients veulent savoir ce que vous faites, combien ça coûte et quand vous intervenez. L\'assistant répond, puis recueille la demande de devis.',
            'dialog' => [['Client', 'Vous intervenez le week-end ?'], ['Assistant', 'Oui, nous intervenons le samedi de 8 h à 14 h. Pour un devis, décrivez-moi le problème et votre quartier, et l\'équipe vous rappelle.']],
            'faq' => [
                ['L\'assistant peut-il donner un prix ferme ?', 'Seulement ceux de vos documents. Pour un devis sur mesure, il recueille les informations et vous passe la demande.'],
            ],
            'related' => ['chatbot-whatsapp', 'assistant-virtuel-site-web', 'chatbot-whatsapp-immobilier'],
        ],

        /* ============================== Pays ============================== */

        'chatbot-whatsapp-burkina-faso' => [
            'type' => 'country', 'path' => 'chatbot-whatsapp-burkina-faso', 'updated' => $updated,
            'label' => 'Burkina Faso',
            'title' => 'Chatbot WhatsApp au Burkina Faso : Ouagadougou, Bobo',
            'description' => 'Un chatbot WhatsApp pour les entreprises du Burkina Faso : prix en FCFA, paiement par Orange Money, Moov Money ou Coris Money, français et langues locales.',
            'h1' => 'Un chatbot WhatsApp pour les entreprises du Burkina Faso',
            'lead' => 'De Ouagadougou à Bobo-Dioulasso, vos clients vous écrivent sur WhatsApp. {brand} répond pour vous à toute heure, en français, avec vos prix en FCFA, et vous prévient quand une commande attend votre confirmation.',
            'facts' => [
                'Monnaie' => 'Franc CFA (FCFA)',
                'Paiement de l\'abonnement' => 'Orange Money, Moov Money, Coris Money ou virement',
                'Villes courantes' => 'Ouagadougou, Bobo-Dioulasso, Koudougou, Ouahigouya',
                'Langues' => 'Français, anglais ; mooré, dioula et peul avec les limites annoncées',
            ],
            'sections' => [
                ['title' => 'Pensé pour le quotidien des entreprises burkinabè', 'text' => [
                    'Vous donnez vos prix en FCFA, vos zones de livraison (Ouagadougou, Bobo-Dioulasso, autres villes) et vos moyens de paiement : l\'assistant les reprend tels quels. Offre Bon plan à {price:bonplan} par mois, essai gratuit de {trial_days} jours.',
                    'Les langues locales (mooré, dioula, peul) sont proposées avec un niveau de fiabilité affiché : testez avec vos propres phrases avant de vous engager, et gardez le français comme langue de repli.',
                ]],
            ],
            'faq' => [
                ['Puis-je payer par Orange Money ou Moov Money ?', 'Oui : vous envoyez la référence du paiement et l\'offre est activée. Coris Money et le virement sont aussi acceptés.'],
                ['L\'assistant comprend-il le mooré ?', 'Le mooré est une langue que l\'assistant comprend de façon limitée : il est classé « expérimental ». Il répond aussi en français. À tester avec vos clients.'],
            ],
            'related' => ['chatbot-whatsapp', 'combien-coute-un-chatbot-whatsapp', 'chatbot-whatsapp-cote-d-ivoire'],
        ],

        'chatbot-whatsapp-cote-d-ivoire' => [
            'type' => 'country', 'path' => 'chatbot-whatsapp-cote-d-ivoire', 'updated' => $updated,
            'label' => 'Côte d\'Ivoire',
            'title' => 'Chatbot WhatsApp en Côte d\'Ivoire : Abidjan, Bouaké',
            'description' => 'Un chatbot WhatsApp pour les entreprises de Côte d\'Ivoire : prix en FCFA, réponses à toute heure, commandes et rendez-vous que vous confirmez, français et dioula.',
            'h1' => 'Un chatbot WhatsApp pour les entreprises de Côte d\'Ivoire',
            'lead' => 'À Abidjan comme à Bouaké ou Yamoussoukro, WhatsApp est le premier canal de vos clients. {brand} répond à leur place avec vos informations et vous passe les commandes et les demandes qui comptent.',
            'facts' => [
                'Monnaie' => 'Franc CFA (FCFA)',
                'Paiement de l\'abonnement' => 'Mobile Money ou virement (référence à nous envoyer)',
                'Villes courantes' => 'Abidjan, Bouaké, Yamoussoukro, San-Pédro, Korhogo',
                'Langues' => 'Français, anglais ; dioula avec les limites annoncées',
            ],
            'sections' => [
                ['title' => 'Un assistant pour les commerces, restaurants et services ivoiriens', 'text' => [
                    'Livraison par quartier, paiement à la livraison ou par Mobile Money, horaires d\'ouverture : vous écrivez vos règles une fois, l\'assistant les applique. Offre Bon plan à {price:bonplan} par mois, essai gratuit de {trial_days} jours.',
                ]],
            ],
            'faq' => [
                ['Mes clients peuvent-ils écrire en dioula ?', 'Le dioula est une langue « assistée » : l\'assistant comprend des phrases courtes et simples, et répond aussi en français. Testez avant de vous engager.'],
                ['Puis-je gérer plusieurs points de vente ?', 'Oui, avec l\'offre Pro ({limit:pro:bots} assistants) ou Business ({limit:business:bots} assistants).'],
            ],
            'related' => ['chatbot-whatsapp', 'chatbot-whatsapp-restaurant', 'chatbot-whatsapp-senegal'],
        ],

        'chatbot-whatsapp-senegal' => [
            'type' => 'country', 'path' => 'chatbot-whatsapp-senegal', 'updated' => $updated,
            'label' => 'Sénégal',
            'title' => 'Chatbot WhatsApp au Sénégal : Dakar, Thiès, prix en FCFA',
            'description' => 'Un chatbot WhatsApp pour les entreprises du Sénégal : réponses à toute heure, prix en FCFA, français et wolof avec leurs limites, demandes que vous confirmez.',
            'h1' => 'Un chatbot WhatsApp pour les entreprises du Sénégal',
            'lead' => 'À Dakar, Thiès, Saint-Louis ou Touba, vos clients vous écrivent sur WhatsApp à toute heure. {brand} répond avec vos informations et vous laisse confirmer les commandes.',
            'facts' => [
                'Monnaie' => 'Franc CFA (FCFA)',
                'Paiement de l\'abonnement' => 'Mobile Money ou virement (référence à nous envoyer)',
                'Villes courantes' => 'Dakar, Thiès, Saint-Louis, Touba, Kaolack',
                'Langues' => 'Français, anglais, arabe ; wolof avec les limites annoncées',
            ],
            'sections' => [
                ['title' => 'Le wolof, avec honnêteté', 'text' => [
                    'Le wolof fait partie des langues que l\'assistant peut utiliser, avec un niveau « assisté » : phrases courtes, prix et chiffres repris tels quels. Il répond aussi en français quand il hésite. Nous vous conseillons de tester avec de vraies phrases de vos clients. Offre Bon plan à {price:bonplan} par mois, essai gratuit de {trial_days} jours.',
                ]],
            ],
            'faq' => [
                ['Le wolof est-il bien géré ?', 'Partiellement : c\'est une langue « assistée ». Les messages vocaux en wolof demandent un serveur de reconnaissance dédié, non inclus par défaut.'],
            ],
            'related' => ['chatbot-whatsapp', 'chatbot-whatsapp-boutique', 'chatbot-whatsapp-mali'],
        ],

        'chatbot-whatsapp-mali' => [
            'type' => 'country', 'path' => 'chatbot-whatsapp-mali', 'updated' => $updated,
            'label' => 'Mali',
            'title' => 'Chatbot WhatsApp au Mali : Bamako, bambara et français',
            'description' => 'Un chatbot WhatsApp pour les entreprises du Mali : prix en FCFA, réponses à toute heure, français et bambara avec leurs limites, commandes que vous confirmez.',
            'h1' => 'Un chatbot WhatsApp pour les entreprises du Mali',
            'lead' => 'À Bamako, Sikasso ou Ségou, vos clients vous écrivent à toute heure. {brand} répond à votre place avec vos informations et vous prévient quand une commande ou un rendez-vous attend votre réponse.',
            'facts' => [
                'Monnaie' => 'Franc CFA (FCFA)',
                'Paiement de l\'abonnement' => 'Mobile Money ou virement (référence à nous envoyer)',
                'Villes courantes' => 'Bamako, Sikasso, Ségou, Kayes, Mopti',
                'Langues' => 'Français, anglais ; bambara avec les limites annoncées',
            ],
            'sections' => [
                ['title' => 'Le bambara, pour les messages vocaux aussi', 'text' => [
                    'Le bambara est l\'une des langues pour lesquelles des modèles libres de reconnaissance vocale existent. {brand} sait s\'y brancher : les vocaux en bambara sont compris si un serveur dédié est configuré. Les réponses restent écrites. Offre Bon plan à {price:bonplan} par mois, essai gratuit de {trial_days} jours.',
                ]],
            ],
            'faq' => [
                ['Mes clients peuvent-ils envoyer des vocaux en bambara ?', 'Oui si un serveur de reconnaissance vocale en bambara est branché ; sinon, l\'assistant les invite à écrire. Les deux cas sont expliqués dans votre espace.'],
            ],
            'related' => ['chatbot-whatsapp', 'chatbot-whatsapp-senegal', 'chatbot-whatsapp-burkina-faso'],
        ],

        'chatbot-whatsapp-comores' => [
            'type' => 'country', 'path' => 'chatbot-whatsapp-comores', 'updated' => $updated, 'currency' => 'KMF',
            'label' => 'Comores',
            'title' => 'Chatbot WhatsApp aux Comores : Moroni, prix en franc comorien',
            'description' => 'Un chatbot WhatsApp pour les entreprises des Comores : prix en franc comorien, réponses à toute heure, français et arabe, commandes que vous confirmez.',
            'h1' => 'Un chatbot WhatsApp pour les entreprises des Comores',
            'lead' => 'À Moroni, Mutsamudu ou Fomboni, vos clients vous écrivent sur WhatsApp. {brand} répond avec vos informations, affiche ses prix en franc comorien et vous passe les demandes qui comptent.',
            'facts' => [
                'Monnaie' => 'Franc comorien (KMF)',
                'Paiement de l\'abonnement' => 'Virement ou paiement mobile (référence à nous envoyer)',
                'Villes courantes' => 'Moroni, Mutsamudu, Fomboni, Mitsamiouli',
                'Langues' => 'Français, arabe, anglais ; shikomori non garanti',
            ],
            'sections' => [
                ['title' => 'Des prix en franc comorien', 'text' => [
                    'Les offres sont affichées en franc comorien : le Bon plan coûte {price:bonplan} par mois. Le shikomori ne fait pas partie des langues garanties : l\'assistant répond en français ou en arabe.',
                ]],
            ],
            'faq' => [
                ['Le shikomori est-il compris ?', 'Pas de façon fiable : c\'est une langue « expérimentale ». L\'assistant répond en français ou en arabe. Testez avec vos clients avant de vous engager.'],
            ],
            'related' => ['chatbot-whatsapp', 'chatbot-whatsapp-maroc', 'combien-coute-un-chatbot-whatsapp'],
        ],

        'chatbot-whatsapp-maroc' => [
            'type' => 'country', 'path' => 'chatbot-whatsapp-maroc', 'updated' => $updated, 'currency' => 'MAD',
            'label' => 'Maroc',
            'title' => 'Chatbot WhatsApp au Maroc : prix en dirhams, français et arabe',
            'description' => 'Un chatbot WhatsApp pour les entreprises du Maroc : prix en dirhams, français et arabe, réponses à toute heure, commandes et rendez-vous que vous confirmez.',
            'h1' => 'Un chatbot WhatsApp pour les entreprises du Maroc',
            'lead' => 'À Casablanca, Rabat, Marrakech ou Tanger, vos clients vous écrivent sur WhatsApp en français ou en arabe. {brand} répond avec vos informations, affiche ses prix en dirhams et vous laisse la décision finale.',
            'facts' => [
                'Monnaie' => 'Dirham marocain (MAD)',
                'Paiement de l\'abonnement' => 'Virement (référence à nous envoyer)',
                'Villes courantes' => 'Casablanca, Rabat, Marrakech, Tanger, Fès',
                'Langues' => 'Français, arabe, anglais',
            ],
            'sections' => [
                ['title' => 'Français et arabe, dans le sens de la lecture', 'text' => [
                    'L\'assistant écrit en arabe (affichage de droite à gauche dans le widget) et en français. La darija n\'est pas garantie : il répond en arabe standard ou en français. Offre Bon plan à {price:bonplan} par mois, essai gratuit de {trial_days} jours.',
                ]],
            ],
            'faq' => [
                ['La darija est-elle comprise ?', 'Partiellement, comme un arabe courant : testez avec des phrases de vos clients. L\'assistant répond en arabe standard ou en français.'],
            ],
            'related' => ['chatbot-whatsapp', 'chatbot-whatsapp-comores', 'chatbot-whatsapp-hotel'],
        ],
    ],
];
