# Supervision des conversations : tout lire, sans rien casser

Le super administrateur lit **toutes** les conversations de la plateforme depuis `/admin/conversations` : les visiteurs de l'assistant de Kouma (page d'accueil) et les clients de chaque entreprise cliente, tous canaux confondus. Ce document dit ce que fait la page, comment elle le calcule, et les limites à respecter.

## 1. Ce que fait la page

| Vue (`?vue=`) | Contenu |
|---|---|
| `toutes` | toutes les conversations, hors zone de test |
| `kouma` | l'assistant de la page d'accueil (`Bot::landing()`, réglage `marketing.landing_bot_key`) |
| `clients` | tous les autres assistants |
| `surveiller` | l'union des signaux d'alerte (attente, sans réponse, mécontent, non servi, erreur, signalée) |
| `prospects` | les visiteurs de Kouma avec un contact, une demande ou un intérêt commercial |
| `questions` | les questions sans réponse, regroupées, toutes entreprises confondues |
| `entreprises` | une ligne par entreprise (volume, part sans réponse, passages à une personne, avis négatifs) |

Filtres (tous dans l'adresse, donc partageables) : `jours` (1, 7, 30, 90, 365), `client`, `assistant`, `canal`, `statut`, `signal`, `q`, `tests`, `tri`. Une valeur inconnue est ignorée (`ChatFilters::fromRequest`).

Une page de détail (`/admin/conversations/{id}`) : diagnostic, échange annoté, suivi (signaler, examinée), résumé IA à la demande, demandes, notes, autres conversations de la même personne. Exports : CSV de la liste filtrée, texte d'une conversation.

## 2. Où est le code

| Fichier | Rôle |
|---|---|
| `App\Services\Chats\ChatFilters` | les choix de la page, validés une seule fois |
| `App\Services\Chats\ChatQuery` | périmètre, signaux, liste, synthèses, direct, conversations liées |
| `App\Services\Chats\ChatDiagnosis` | issue, note sur 100, signaux en clair, frise de l'échange (sans IA) |
| `App\Services\Chats\ChatSummarizer` | résumé IA à la demande, avec repli sans IA |
| `App\Http\Controllers\Admin\ChatSupervisionController` | pages, notes, exports, journal |
| `App\Models\ConversationNote` | notes, signalements, « examinée », résumé (table `conversation_notes`) |
| `resources/views/admin/chats/` | la liste et le détail |

## 3. Signaux : définitions exactes

| Signal | Règle |
|---|---|
| `attente` | statut `needs_human` ou `human`, dernier message du client il y a plus de 30 minutes (`ChatQuery::WAIT_MINUTES`), aucun message de conseiller depuis |
| `sans_reponse` | au moins deux réponses de l'assistant avec `meta.grounded = false` |
| `mecontent` | un avis `meta.feedback = down`, ou un message du client avec un mot de `ChatQuery::COMPLAINTS` |
| `non_servi` | une réponse dont `meta.reason` est `bot_inactive`, `workspace_suspended`, `trial_expired` ou `quota_exceeded` |
| `erreur` | une réponse dont `meta.reason` est `llm_error` ou `refusal` |
| `prospect` | un e-mail ou un téléphone, une demande (`leads`), ou un mot de `ChatQuery::INTENT` dans un message du client |
| `signale` | une note de type `flag` posée par l'équipe |

Tout est du SQL portable (MySQL et SQLite) : chemins JSON (`meta->grounded`), sous-requêtes, `LIKE`. Les listes n'ont pas de requête par ligne : les compteurs (messages, sans réponse, avis, demandes) sont des sous-requêtes de la même requête.

**Note de qualité** (`ChatDiagnosis::score`) : 100, moins 15 par réponse sans information (45 au plus), moins 35 si le client n'a pas été servi, moins 20 en cas de panne de l'IA, moins 25 par avis négatif (50 au plus), moins 20 en cas d'attente de plus de 30 minutes, moins 10 pour une question répétée, moins 10 pour une plainte sans conseiller ; plus 5 par avis positif (10 au plus). Mentions : 85 et plus Bonne, 65 Correcte, 40 À surveiller, en dessous Problématique. C'est une aide à la lecture.

## 4. Principes à ne pas casser

1. **Super administrateur seulement** : groupe de routes `superadmin`. Un administrateur d'équipe et un client reçoivent un 403 (`ChatSupervisionTest`).
2. **Lire hors du périmètre d'entreprise** : `Conversation`, `Message`, `Lead` et `Bot` portent `BelongsToWorkspace`. Ici, toute lecture passe par `withoutGlobalScopes()` ou par `DB::table` : sinon un super administrateur « entré » dans l'espace d'un client ne verrait que cet espace. Les routes n'utilisent pas la liaison de modèle (`{id}` numérique) pour la même raison.
3. **Tout est journalisé** : `chat.viewed` (une fois par heure, par personne et par conversation, via le cache), `chat.note`, `chat.flagged`, `chat.reviewed`, `chat.summarized`, `chat.exported`. L'espace du client concerné est renseigné.
4. **Numéros masqués** (`ChatQuery::maskPhone`) pour les clients des entreprises, dans la liste, le détail, le CSV et le texte. Seuls les visiteurs de Kouma (qui ont écrit à la plateforme) sont montrés en clair, dans le détail, pour pouvoir les recontacter. Le CSV ne contient jamais d'e-mail, et neutralise les cellules qui commencent par `= + - @`.
5. **Les notes de l'équipe ne sortent pas** : `ConversationNote` n'a pas `BelongsToWorkspace` et aucune page cliente ne la lit (`ChatSupervisionTest::test_notes_flags_and_reviews_stay_with_the_team_and_are_logged`).
6. **Le résumé IA est un geste volontaire** : jamais lancé tout seul, jamais imputé au volume du client, limité à 6 par minute. Le fil est donné au modèle comme une donnée (balises neutralisées, y compris `<conversation>`). Sans IA, un résumé simplifié est composé localement.
7. **Aucune action sur la conversation** : la page ne répond pas à la place d'un conseiller. Pour agir, on « entre dans l'espace » du client (action déjà journalisée).
8. **Les essais de la zone de test** (`channel = playground`) sont exclus par défaut des vues et des chiffres.

## 5. Limites connues

- Les chiffres viennent des champs enregistrés avec chaque message (`meta.grounded`, `meta.reason`, `meta.feedback`). Les conversations anciennes, d'avant l'enregistrement de ces champs, apparaissent sans signal.
- Les mots de plainte et d'intérêt commercial sont des listes courtes de mots français et anglais (`ChatQuery::COMPLAINTS`, `INTENT`) : une plainte dans une autre langue n'est repérée que par un avis négatif ou par un passage à une personne.
- « Prospect » est une aide au tri, pas un score d'achat.
- La carte des heures d'affluence lit au plus 20 000 messages.
- La recherche cherche un mot dans tous les messages : sur une très grosse base, restreindre la période.
- Informer les personnes concernées : la page Confidentialité mentionne la lecture par l'équipe. Faire relire par un juriste.
