# Guide de l'équipe Kouma

## À qui s'adresse ce guide

Ce guide est destiné à l'**équipe** qui accompagne les entreprises clientes : créer leurs espaces, enregistrer leurs paiements, activer WhatsApp, les aider à configurer leur assistant et répondre à leurs questions. Il décrit ce que vous voyez et faites dans la **console d'administration** (`/admin`), avec les procédures à suivre.

Deux rôles existent dans l'équipe :

| | Admin | Super admin |
|---|---|---|
| Vue d'ensemble, espaces clients, entrer dans un espace | oui | oui |
| Paiements, recharges, options, changements d'offre, suspension | oui | oui |
| Demandes d'offre, demandes d'activation WhatsApp | oui | oui |
| Consommation, coûts et solde Twilio | non | oui |
| Création et modification des offres et des prix | non | oui |
| Fournisseurs d'IA, clés, moteur de recherche | non | oui |
| Équipe (ajouter, changer les rôles) | non | oui |
| Paramètres de la plateforme | non | oui |
| Journal d'audit | non | oui |

Votre rôle est affiché sous votre nom en bas du menu de gauche. Les entrées réservées au super admin n'apparaissent pas pour un admin.

> **À savoir :** tout ce que vous faites est **journalisé** (qui, quoi, quand, dans quel espace). Travaillez toujours depuis votre propre compte, et ne partagez jamais votre mot de passe.

### Les principes de l'accompagnement

1. **Le client d'abord, en français, avec un ton chaleureux.** Beaucoup de clients découvrent l'outil : expliquez simplement, sans jargon.
2. **Ne demandez jamais un mot de passe.** Pour aider, entrez dans l'espace du client depuis la console.
3. **Les mots de passe provisoires** sont affichés une seule fois : transmettez-les par un canal sûr et invitez le client à les changer.
4. **Confidentialité.** Les conversations et documents d'un client ne se partagent avec personne d'autre.
5. **Dites ce que vous faites.** Confirmez au client chaque action (« J'ai activé votre offre jusqu'au… »).

## 1. Se connecter à la console

Connectez-vous avec votre adresse e-mail et votre mot de passe depuis la page **Se connecter**, puis ouvrez `/admin` (ou le lien « Administration »). Le menu de gauche a deux parties :

- **Équipe** (ou « Super admin ») : Vue d'ensemble, Espaces clients, Demandes WhatsApp, Demandes d'offre (et, pour le super admin, Consommation, Offres et tarifs, IA et fournisseurs, Équipe, Paramètres, Journal).
- **Espace : nom du client**, quand vous êtes entré dans un espace : les pages du client (Tableau de bord, Assistants, Demandes, Alertes, Abonnement).

En bas du menu : **Ressources** (Aide et guides, ce guide, Voir le site).

## 2. La vue d'ensemble

C'est votre tableau de bord de pilotage :

- **Quatre chiffres** : espaces clients (et combien paient), assistants (et canaux WhatsApp actifs), conversations des 7 derniers jours, réponses de l'IA ce mois-ci.
- **Encaissé ce mois-ci** : la somme des paiements enregistrés depuis le début du mois, **une ligne par devise** (on n'additionne jamais des FCFA et des euros). S'il n'y a rien, « 0 FCFA » s'affiche.
- **Demandes d'offre à traiter** : les clients qui veulent changer d'offre, avec un bouton vers leur fiche.
- **Activations WhatsApp à traiter** : les demandes d'activation en attente.
- **Abonnements qui arrivent à échéance** : les clients à relancer pour renouveler.
- **Un bandeau rouge** si un fournisseur d'IA est en difficulté (clé expirée, quota épuisé). Le super admin y trouve un lien vers la chaîne de fournisseurs.

> **Astuce :** commencez votre journée par cette page : demandes en attente, échéances, alertes.

## 3. Les espaces clients

**Espaces clients** liste toutes les entreprises. Vous pouvez **rechercher** par nom et **filtrer par offre**. Chaque ligne a un bouton **Entrer dans l'espace**.

### Créer un espace

Bouton **Nouvel espace client** (ou page `/admin/workspaces/create`) :

| Champ | Remarque |
|---|---|
| Nom de l'entreprise | Obligatoire |
| Nom du responsable | Le compte propriétaire |
| E-mail du responsable | Il sert d'identifiant de connexion, il doit être unique |
| Pays, Téléphone | Facultatifs, utiles pour le suivi |
| Offre de départ | Une offre gratuite démarre toujours par un essai limité dans le temps. Une offre payante choisie ici est **offerte sans échéance** : pour enregistrer un paiement, utilisez la fiche de l'espace après création |

À la création, un **mot de passe provisoire** est généré et **affiché une seule fois**. Notez-le immédiatement et transmettez-le au responsable : il pourra le changer depuis son profil.

> **Attention :** la devise d'un nouvel espace est le **FCFA** par défaut. Si le client paie dans une autre devise, réglez-la sur sa fiche (« Devise du compte ») avant d'enregistrer le premier paiement.

### La fiche d'un espace

Cliquez sur une entreprise. En haut : trois jauges (réponses du mois, assistants, sources) et le bouton **Entrer dans l'espace**. Puis, par rubriques :

#### Ce que le client reçoit automatiquement

Vous n'avez pas à écrire à la main ce qui part déjà tout seul : le **reçu de paiement** (offre, montant, moyen, référence, période) dès que vous enregistrez un paiement, le message « **WhatsApp activé** » quand vous passez le canal à « Actif », les rappels d'échéance et de fin d'essai, et le message de bienvenue. Chacun part par e-mail, dans la cloche du client et sur son téléphone s'il a activé les notifications. Vous gardez les messages types ci-dessous pour **personnaliser** l'accompagnement (conseils, relance, explication).

#### Activité dans l'application

Visites, jours actifs et temps passé sur les 30 derniers jours, pages les plus visitées, actions faites (créer un assistant, ajouter une source...) et une **note de santé** de 0 à 100. Un client sans visite depuis plus de deux semaines est « à relancer » : un message ou un appel suffit souvent. Ces chiffres sont réservés à l'équipe : un client ne les voit jamais.

#### Enregistrer un paiement

À utiliser **après réception** d'un paiement Mobile Money, d'un virement ou d'espèces. L'offre est **activée** et la période **prolongée**.

| Champ | Conseil |
|---|---|
| Offre | L'offre payée |
| Durée couverte (mois) | 1 pour un mois, 3 pour un trimestre, etc. |
| Montant reçu | Pré-rempli avec le prix de l'offre dans la devise du compte : corrigez si le client a payé autre chose |
| Devise du paiement | Celle du compte par défaut |
| Moyen de paiement | Orange Money, Moov Money, Coris Money, virement, espèces… |
| Référence | La référence de la transaction : **toujours la noter**, elle sert en cas de litige |
| Date du paiement | Par défaut aujourd'hui |

Règles de calcul : un renouvellement **prolonge à partir de l'échéance actuelle** (le client ne perd pas de jours) ; un paiement après l'expiration **démarre une période neuve** et lève une suspension pour impayé ; si le client avait demandé cette offre, la **demande est close** automatiquement.

#### Recharger des messages WhatsApp

Le client dépasse le volume WhatsApp inclus et paie par Mobile Money un lot de messages : saisissez le nombre de messages ajoutés, le moyen de paiement et la référence. Les messages s'ajoutent à son crédit et servent **après** le volume inclus dans son offre.

#### Options à la carte

Une option achetée en plus de l'offre (par exemple l'import des discussions WhatsApp) : activez-la ou retirez-la. Elle est aussi activée quand vous **approuvez la demande** du client dans « Demandes d'offre ».

#### Changer l'offre sans paiement

Pour un **geste commercial**, un **essai** ou une **correction**. Choisissez l'offre et, si besoin, la date de fin (« Jusqu'au »). Sans date de fin, une offre payante reste active ; l'offre gratuite reçoit la durée d'essai par défaut. Ajoutez un **motif** : il est journalisé.

#### Suspendre ou réactiver

Le bouton de suspension (avec un motif facultatif) **arrête les réponses** des assistants du client et l'empêche d'utiliser son tableau de bord. À utiliser pour un impayé persistant, un abus ou une demande du client. La réactivation remet tout en service. Un bandeau rouge rappelle qu'un espace est suspendu.

#### Utilisateurs

La liste des comptes de l'espace. Vous pouvez :

- **Ajouter** un utilisateur (nom et e-mail) : un mot de passe provisoire est affiché une seule fois. Le nombre d'utilisateurs est limité par l'offre.
- **Réinitialiser le mot de passe** d'un utilisateur : un nouveau mot de passe provisoire est affiché une seule fois.
- **Désactiver ou réactiver** un compte (un utilisateur désactivé ne peut plus se connecter).

#### Assistants

La liste des assistants de l'espace. Si le client n'en a aucun : « Entrez dans l'espace pour en créer un à la place du client. »

#### Informations

Le nom, le pays, le téléphone, la **devise du compte** (elle règle les prix, les paiements et la facturation de ce client) et des **notes internes**. Gardez-y le contexte utile à l'équipe : ce qui a été promis, les particularités, les dates de relance.

#### Historique des paiements

Les 20 derniers paiements : date, offre, montant, moyen, période, personne qui l'a saisi.

## 4. Entrer dans l'espace d'un client

**Entrer dans l'espace** vous donne **les pages du client** (Tableau de bord, Assistants, Demandes, Alertes, Abonnement) comme si vous étiez lui. Un bandeau en haut le rappelle : « Vous gérez l'espace X en tant que super admin. Tout ce que vous modifiez ici est enregistré au nom de ce client. »

Cela sert à :

- **Créer et configurer l'assistant à sa place** (c'est l'offre « on s'occupe de tout »).
- **Reproduire un problème** tel que le client le voit.
- **Régler la devise, les alertes ou les langues** sans lui demander de manipulation.

Pour sortir, cliquez sur **Quitter cet espace** dans le bandeau.

> **Important :** vos modifications sont faites **au nom du client** et visibles dans son historique. Informez-le toujours de ce que vous avez changé.

### Le service « on s'occupe de tout » : la liste de contrôle

Pour un client qui nous confie la mise en place, déroulez cette liste dans son espace :

1. **Créer l'assistant** : nom, métier, profil de l'entreprise, ton, langues (voir le guide client, section 2).
2. **Récolter les informations** : tarifs, horaires, adresse, livraison, paiements, catalogue. Demandez des documents existants (PDF, Word, Excel), ou son site, ou sa page Facebook (le contenu est à copier-coller).
3. **Importer les connaissances** : documents, catalogue (Excel ou CSV avec le modèle), photos d'affiches ou de menus, site web, questions / réponses pour les cas sensibles.
4. **Tester** avec les quinze questions les plus fréquentes, et corriger.
5. **Régler** le message d'accueil, les questions suggérées, la couleur, les **sites autorisés**.
6. **Installer le widget** sur son site (ou lui envoyer la ligne de code et l'endroit où la coller), ou lui montrer la page de démonstration.
7. **Activer WhatsApp** (section 6 ci-dessous) puis ajouter le paquet de **modèles de messages**.
8. **Choisir ses alertes** (e-mail, WhatsApp, rappels) et renseigner les numéros.
9. **Former** : montrer les conversations, les demandes, comment prendre la main, comment répondre aux questions sans réponse.
10. **Confirmer par écrit** ce qui a été fait, et planifier un point à une semaine pour regarder les questions sans réponse ensemble.

## 5. Les demandes d'offre

Chaque nouvelle demande vous est signalée sur votre **téléphone** (si vous avez activé les notifications), dans votre cloche et par e-mail à l'adresse de réception des alertes de l'équipe : vous n'avez pas à surveiller la page. Il en va de même des demandes d'option à la carte et des demandes d'activation WhatsApp.

Quand un client clique sur **Choisir** une offre (ou demande une option), une demande apparaît dans **Demandes d'offre**, avec la liste filtrable par statut. Le client voit « Demande en cours » et les instructions de paiement.

Procédure :

1. **Contactez le client** si besoin et confirmez le moyen de paiement.
2. À **réception du paiement**, ouvrez sa fiche et **enregistrez le paiement** : l'offre est activée et la demande se ferme d'elle-même.
3. Pour une **option à la carte**, utilisez **Approuver** dans la liste des demandes (après réception du paiement) : l'option est activée.
4. Pour **refuser**, utilisez **Refuser** et expliquez le motif dans la note visible par le client.

> **À savoir :** approuver une demande d'*offre* ne l'active pas : c'est l'**enregistrement du paiement** qui active l'offre et prolonge la période. Pour une option à la carte, c'est l'approbation qui l'active.

## 6. Activer WhatsApp pour un client

### Ce que le client a fait

Dans l'onglet **Canaux** de son assistant, il a rempli le nom de son entreprise, le numéro souhaité, le pays et des précisions, puis cliqué sur **Demander l'activation**. Vous recevez un e-mail et la demande apparaît dans **Demandes WhatsApp** (et dans la vue d'ensemble).

### Choisir le fournisseur

| | Meta direct | Twilio |
|---|---|---|
| Coût | Le moins cher (aucun frais d'intermédiaire) | Environ 0,005 $ de plus par message entrant et sortant |
| Démarrage | Plus long (compte WhatsApp Business, vérification) | Plus rapide |
| Quand | Cas général | Secours, démonstration, ou démarrage rapide |

Le réglage « Fournisseur conseillé pour les nouveaux canaux » (super admin) propose par défaut Meta direct s'il est configuré, sinon Twilio.

### Les étapes

1. **Ouvrez la demande** (Demandes WhatsApp). Lisez ce que le client a écrit (numéro déjà utilisé sur WhatsApp Business ? page Facebook liée ?).
2. **Prérequis à vérifier avec le client** : un numéro qui peut recevoir un SMS ou un appel de vérification, **non utilisé** dans l'application WhatsApp ou WhatsApp Business, un accès au compte Meta Business de l'entreprise, un nom d'affichage cohérent avec l'entreprise.
3. **Réalisez l'enregistrement** du numéro chez Meta ou chez Twilio (voir le guide du super admin et `docs/WHATSAPP.md`).
4. **Remplissez la section « Configuration du canal »** :
   - **Fournisseur** : Meta ou Twilio.
   - **Numéro affiché** : le numéro tel que le client le voit.
   - **Meta** : `phone_number_id`, `WABA ID` (facultatif), `jeton d'accès` (utilisateur système).
   - **Twilio** : `Account SID`, `Auth Token`, `numéro expéditeur WhatsApp` ou `Messaging Service SID`. Laissez vides le SID et le jeton pour utiliser le compte Twilio de la plateforme.
   - **État du canal** : en attente, actif ou désactivé.
5. **Enregistrez** : sur Twilio, l'**adresse du webhook** s'affiche. Collez-la dans la console Twilio, dans « When a message comes in », en **POST**. Pour Meta, un seul webhook sert tous les clients.
6. Cliquez sur **Tester la connexion** (« Vérifie le jeton auprès de Meta ou de Twilio »).
7. Passez l'**état du canal** à **actif** et le **statut de la demande** à « Active ». Saisissez le **message visible par le client** (par exemple « Votre WhatsApp est activé : écrivez à ce numéro pour essayer. »).
8. **À l'activation**, si l'offre du client inclut les modèles, le **paquet de base et celui de son métier** de modèles de messages partent automatiquement à l'approbation de WhatsApp.
9. **Essayez** : écrivez au numéro depuis un téléphone, vérifiez que l'assistant répond et que la conversation apparaît dans le tableau de bord du client.
10. **Prévenez le client** et montrez-lui comment suivre ses conversations.

### Les statuts de la demande

| Statut | Signification pour le client |
|---|---|
| Demandée | « Votre demande est en cours de traitement par notre équipe technique » |
| En cours | Vous avez commencé |
| Active | Le canal est en service |
| Refusée | Le client voit votre message : expliquez la raison et la solution possible |

## 7. Accompagner le client au quotidien

### Les questions fréquentes de l'équipe

**Le client n'est plus facturé correctement.** Vérifiez la devise du compte (fiche de l'espace, « Informations ») et l'historique des paiements.

**Le client veut une autre devise.** Changez-la sur sa fiche ou, dans son espace, sur la page Abonnement. Ses paiements passés gardent leur devise.

**Un client veut plus d'utilisateurs.** Le nombre d'utilisateurs dépend de l'offre : ajoutez-les sur la fiche (dans la limite de l'offre), ou proposez une offre supérieure.

**Un client a oublié son mot de passe.** Il utilise « Mot de passe oublié ? » sur la page de connexion. Sinon, **réinitialisez** son mot de passe sur la fiche (utilisateurs) et transmettez le mot de passe provisoire par un canal sûr.

**Un client veut supprimer son compte.** Il peut le faire lui-même depuis **Mon profil**. La suppression est définitive : prévenez-le de télécharger ce qu'il veut garder.

### Diagnostic : « l'assistant ne répond pas »

Parcourez cette liste dans l'ordre, dans l'espace du client :

1. **L'assistant est-il actif ?** Réglages, case « Assistant actif ».
2. **L'espace est-il suspendu ?** Un bandeau rouge sur la fiche l'indique.
3. **L'essai est-il terminé ?** L'assistant est en pause jusqu'au choix d'une offre.
4. **Le quota de réponses du mois est-il atteint ?** Tableau de bord, « Consommation du mois ». Proposez une offre supérieure.
5. **Un fournisseur d'IA est-il en difficulté ?** La vue d'ensemble affiche un bandeau rouge. Prévenez le super admin.
6. **Pour WhatsApp** : le canal est-il actif ? la conversation est-elle en « prise en main » (l'assistant se tait) ? la fenêtre de 24 heures est-elle fermée (seul un modèle approuvé peut partir) ? le webhook est-il bien configuré ?
7. **Pour le widget** : la ligne de code est-elle bien placée avant `</body>` ? le domaine est-il dans les **sites autorisés** ? l'assistant est-il actif ?

### Diagnostic : une source en « Échec »

Le motif est affiché sur la source. Causes courantes : fichier illisible ou protégé par mot de passe, format ancien (`.xls` : demander un `.xlsx` ou CSV), site qui bloque la lecture ou construit en JavaScript (utiliser l'onglet Texte), délai dépassé sur un très gros fichier (le découper). Cliquez sur **Relire** après correction.

### Diagnostic : « l'assistant se trompe »

1. Ouvrez l'onglet **Tester** et reproduisez la question : le panneau « Comment l'assistant a répondu » montre les extraits utilisés.
2. Si l'information est **absente** : ajoutez-la (document, texte, question / réponse).
3. Si l'information est **périmée** : supprimez ou remplacez la source.
4. Si l'information est **bonne mais mal formulée** : ajustez la consigne.
5. Regardez les **questions sans réponse** de l'onglet Analytique et répondez-y avec le client.

## 8. Envoyer une notification aux clients

Le menu **Notifications** permet d'envoyer une promotion, une nouveauté ou un message important **directement sur le téléphone** des clients (et dans la cloche de leur application), avec le compteur sur l'icône de l'application installée.

En haut de la page, vous voyez la **portée** : combien de clients actifs, combien sont joignables sur téléphone, combien d'appareils. Si peu de clients ont activé les notifications, rappelez-leur de toucher « Activer les notifications » sur leur tableau de bord : les autres reçoivent quand même votre message dans la cloche, à leur prochaine visite.

### Écrire et envoyer

1. Cliquez sur **Nouvelle notification**.
2. **Titre** (65 caractères) et **message** (178 caractères) : court, concret, avec ce que le client y gagne. L'aperçu à droite montre la notification telle qu'elle apparaîtra sur un téléphone.
3. **Où mène la notification** : une page de l'application (Abonnement, Assistants...), ou un lien `https://`.
4. **Type de message** :
   - **Promotion ou nouveauté** : respecte les choix des clients. Un client qui a refusé les promotions ne la reçoit pas, un client ne reçoit **qu'une promotion par jour**, et rien ne part la nuit (les messages du soir attendent 7 h).
   - **Message important** : information de service (panne, changement de tarif, maintenance). Reçu par tous, même ceux qui refusent les promotions. **À réserver aux vraies urgences** : un client qui reçoit trop de messages importants finit par bloquer toutes les notifications.
5. **À qui** : tous les clients, les clients d'une ou plusieurs offres, des entreprises choisies, ou **les clients qui ne sont pas revenus depuis N jours** (un bon moyen de les faire revenir). Le nombre de personnes et d'appareils touchés se met à jour en direct.
6. Cliquez sur **M'envoyer un essai** : le message arrive sur vos propres appareils (activez d'abord vos notifications). Vérifiez le texte et le lien.
7. **Envoyer maintenant**, ou cochez **Programmer l'envoi** et choisissez la date et l'heure. Le brouillon se garde pour plus tard.

> **Astuce :** les meilleurs moments sont la fin de matinée et le début de soirée. Un seul message clair vaut mieux que trois : dites une chose, avec un lien.

> **Attention :** l'envoi est définitif. Relisez le titre, le message et l'audience avant de cliquer. Tant qu'une campagne est « Programmée », vous pouvez encore la modifier ou l'annuler.

### Suivre les résultats

Ouvrez une campagne pour voir : les personnes visées, celles qui l'ont reçue, le nombre d'envois sur téléphone et les échecs, celles qui ont été écartées (refus de ce type de message, ou promotion déjà reçue aujourd'hui), les messages retardés jusqu'au matin, et surtout **le nombre de personnes qui l'ont ouverte**. Un envoi important part par morceaux : la page se met à jour toute seule, et le reste part dans les minutes qui suivent.

Chaque envoi est consigné dans le **journal** (qui, quand, à qui). Vous pouvez aussi **dupliquer** une campagne passée.

### Les notifications que vous recevez

Vous aussi, vous êtes prévenu sur votre téléphone : demande d'offre, demande d'option, demande d'activation WhatsApp, solde Twilio bas. Activez-les depuis la cloche, puis **Préférences de notifications**.

## 9. Les messages types

Adaptez ces modèles à votre ton. Ils sont écrits pour être clairs et chaleureux.

> **À savoir :** le client reçoit déjà automatiquement un reçu de paiement et un message d'activation de WhatsApp. Utilisez ces messages types pour ajouter un mot personnel, pas pour remplacer le reçu.

**Activation d'une offre**
> Bonjour, nous avons bien reçu votre paiement de [montant] (référence [référence]). Votre offre [offre] est activée jusqu'au [date]. Merci de votre confiance ! Si vous avez la moindre question, répondez-nous ici.

**WhatsApp activé**
> Bonne nouvelle : votre WhatsApp est activé. Écrivez « Bonjour » au [numéro] pour l'essayer. Vos conversations apparaissent dans l'onglet Conversations de votre tableau de bord. Nous restons à votre disposition.

**Demande d'informations pour WhatsApp**
> Pour activer WhatsApp, il nous faut : le numéro à utiliser (qui doit pouvoir recevoir un SMS ou un appel, et ne pas être utilisé dans WhatsApp), le nom d'entreprise tel qu'il doit s'afficher, et un accès au compte Meta Business de l'entreprise si vous en avez un. Pouvez-vous nous les envoyer ?

**Relance avant l'échéance**
> Bonjour, votre abonnement arrive à échéance le [date]. Pour le renouveler, payez selon les indications de la page Abonnement, puis envoyez-nous la référence. Nous activons la nouvelle période dès réception.

## 10. Bonnes pratiques et limites

- **Documentez** chaque action dans les notes internes de l'espace.
- **Ne promettez pas** de date d'activation de WhatsApp : elle dépend de Meta. Donnez une fourchette honnête et tenez le client informé.
- **Vérifiez deux fois** le montant et la devise avant d'enregistrer un paiement : un paiement s'annule difficilement.
- **Ne modifiez pas** les offres, les prix ni les paramètres de la plateforme : c'est le rôle du super admin.
- **Signalez** au super admin tout comportement anormal (bandeau rouge IA, clients qui ne reçoivent plus de réponse, pics de consommation).
- **Respectez la confidentialité** : ne copiez jamais les conversations ou documents d'un client en dehors de la plateforme.

## Contacts et escalade

En cas de doute ou de blocage technique, contactez le super admin. Pour un incident qui touche plusieurs clients (assistants muets, WhatsApp coupé, site inaccessible), prévenez-le **immédiatement** en précisant l'heure, le nombre de clients touchés et ce que vous avez déjà vérifié.
