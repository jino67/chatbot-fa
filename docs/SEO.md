# Référencement (SEO)

Ce document dit ce qui est déjà fait dans le code, ce qu'il faut faire **après la mise en ligne**, et comment ajouter une page.

## 1. La Search Console est-elle obligatoire ?

Non : Google trouve et classe un site sans elle. Mais elle est **fortement recommandée**, gratuite, et prend dix minutes :

- elle permet de **dire à Google que le site existe** (envoi du plan du site) au lieu d'attendre qu'il le découvre seul, ce qui peut prendre des semaines pour un domaine neuf ;
- elle montre **sur quelles recherches le site apparaît**, combien de personnes cliquent, et quelles pages posent problème (page non indexée, erreur, page lente) ;
- elle avertit en cas de problème de sécurité ou de pénalité.

À faire aussi : **Bing Webmaster Tools** (importe la propriété depuis la Search Console en deux clics). Bing alimente aussi d'autres moteurs et certains assistants.

### Mise en route (après le téléversement du site sur kouma.site)

1. Ouvrir la Search Console, « Ajouter une propriété », choisir **Domaine** et saisir `kouma.site`.
2. Valider par **enregistrement DNS** (un champ TXT à ajouter dans la zone DNS de l'hébergeur ; c'est aussi là que se trouvent SPF, DKIM et DMARC).
3. Menu « Sitemaps » : envoyer `https://kouma.site/sitemap.xml`.
4. Menu « Inspection de l'URL » : coller `https://kouma.site/`, puis « Demander une indexation ». Répéter pour `/ressources` et les trois ou quatre pages les plus importantes.
5. Revenir dans une semaine : menu « Pages » pour voir ce qui est indexé, menu « Performances » pour voir les recherches.

## 2. Ce que le code fait déjà

| Sujet | Mise en œuvre |
|---|---|
| Balises de chaque page | Composant `components/seo.blade.php` : titre, description, adresse canonique, `robots` (`index, follow, max-image-preview:large`), Open Graph, carte `summary_large_image`, image de partage `public/og-image.png` (1200 × 630). |
| Données structurées | Accueil : `SoftwareApplication`, `WebSite` (avec les autres graphies du nom), `Organization`, `FAQPage`. Pages de contenu : `BreadcrumbList`, `Article` (guides) ou `WebPage`, `FAQPage`. Page « Ressources » : `CollectionPage`. |
| Pages de contenu | 21 pages décrites dans `config/seo.php` : 3 solutions, 4 guides, 8 métiers, 6 pays. Elles répondent aux recherches simples (« chatbot WhatsApp », « combien coûte un chatbot WhatsApp », « réponse automatique WhatsApp Business », « chatbot WhatsApp Ouagadougou »...). |
| Plan du site | `/sitemap.xml` : accueil, ressources, inscription, développeurs, pages juridiques, toutes les pages de contenu, avec `lastmod`. |
| robots.txt | Bloque les espaces privés (`/admin`, `/dashboard`, `/bots`, `/billing`, `/profile`, `/demandes`, `/alertes`, `/import`, `/api/`, `/webhooks/`, `/demo/`, `/media/`, `/devise/`) et annonce le plan du site. Connexion et mot de passe restent lisibles pour que leur `noindex` soit vu. |
| `llms.txt` | `/llms.txt` : résumé du site pour les assistants d'IA (qui nous sommes, liens vers les pages). |
| Pages privées | Espace client : `noindex`. Connexion et mots de passe : `noindex, nofollow`. Inscription : indexable, avec sa description. |
| Maillage interne | Pied de page (solutions, guides, métiers, pays), sections « Métiers » et « WhatsApp » de l'accueil, fil d'Ariane et « À lire ensuite » sur chaque page. |
| Adresse unique | `.htaccess` : `http` vers `https`, `www` vers la version sans `www`, compression, cache long des fichiers versionnés. |
| Noms proches | `Kuma`, `Couma`, `Cuma`, `Koumah` déclarés dans les données structurées, le pied de page et une question de la FAQ (seulement tant que la marque reste Kouma). |
| Vitesse | Polices de caractères en préchargement, CSS et JS compressés et versionnés, widget chargé après la page, animations coupées si l'utilisateur les refuse. |
| Application installable | Manifeste, icônes et page hors connexion : utile pour la qualité perçue, pas pour le classement. |

Les prix et quotas cités dans les pages viennent **des offres en base** (jetons `{price:offre}`, `{limit:offre:quota}`, `{trial_days}`) : changer un prix dans l'admin met à jour les guides. Ils sont affichés dans la devise de la page (FCFA par défaut, franc comorien pour les Comores, dirham pour le Maroc), jamais selon le choix du visiteur : les moteurs voient toujours les mêmes chiffres.

## 3. À faire après la mise en ligne

1. `APP_URL=https://kouma.site` dans `.env`, puis `php artisan config:clear`. Sans cela, le plan du site et les adresses canoniques pointent vers la mauvaise adresse.
2. Vérifier `https://kouma.site/robots.txt`, `/sitemap.xml` et `/llms.txt` dans un navigateur.
3. Search Console et Bing (section 1).
4. Tester une page avec le [test des résultats enrichis](https://search.google.com/test/rich-results) (données structurées) et l'aperçu de partage WhatsApp (envoyer l'adresse à soi-même : l'image et le titre doivent apparaître ; le cache de WhatsApp est long, c'est normal si une ancienne version s'affiche).
5. **Fiche Google Business Profile** si l'entreprise a un local ou un point de contact (gratuit, très utile pour « chatbot Ouagadougou » ou « agence chatbot Bobo »).
6. Renseigner `BRAND_WHATSAPP` (ou le numéro dans l'admin) : les boutons WhatsApp du site apparaissent alors, et les visiteurs peuvent écrire en un geste.
7. Rediriger les autres graphies du nom si les domaines correspondants sont achetés (`kuma`, `couma`...) : redirection 301 vers `https://kouma.site`.

## 4. Ce qui fait vraiment monter : le contenu et les liens

Les moteurs classent d'abord les pages qui répondent **précisément** à une question, et qui sont citées ailleurs. Ce que l'équipe peut faire, sans outil :

- **Publier régulièrement** : une page ou un guide par quinzaine. Idées : « chatbot WhatsApp pour pharmacie », « pour pressing », « pour auto-école », « Mobile Money et abonnement en ligne », « modèles de messages WhatsApp : exemples », « comment répondre à un client mécontent sur WhatsApp ».
- **Écrire pour un humain** : une question, une réponse claire, des exemples locaux (villes, monnaies, habitudes de paiement). Jamais de répétition forcée des mots-clés.
- **Obtenir des liens** : annuaires d'entreprises locaux, articles invités, partenaires (comptables, agences web, incubateurs), réseaux sociaux de l'entreprise, témoignages de clients **réels** avec leur accord.
- **Ne pas inventer** d'avis, de chiffres ou de captures : les moteurs et les lecteurs le voient vite, et c'est contraire aux conditions de Google.
- **Montrer de vrais cas** : dès qu'un client pilote accepte, publier son témoignage et une capture (avec son accord) sur une page dédiée.

## 5. Ajouter ou modifier une page

Tout est dans `config/seo.php` ; aucune vue à écrire.

```php
'chatbot-whatsapp-pharmacie' => [
    'type' => 'sector', 'sector' => 'sante', 'path' => 'chatbot-whatsapp-pharmacie', 'updated' => '2026-11-01',
    'label' => 'Pharmacie',
    'title' => 'Chatbot WhatsApp pour pharmacie : horaires et gardes',   // 62 caractères au plus
    'description' => '...',                                              // entre 70 et 165 caractères
    'h1' => '...',
    'lead' => '...{brand}...',
    'sections' => [['title' => '...', 'text' => ['...'], 'list' => ['...'], 'steps' => ['...']]],
    'faq' => [['Question ?', 'Réponse.']],
    'related' => ['chatbot-whatsapp', 'chatbot-whatsapp-clinique'],
],
```

- Types : `solution` et `sector` et `country` (adresse à la racine), `guide` (adresse sous `/guides/`, précisée dans `path`).
- Jetons : `{brand}`, `{trial_days}`, `{price:bonplan}`, `{limit:pro:bots}`.
- Pas de promesse que le produit ne tient pas : ne citer qu'une fonction qui existe, et une langue seulement avec le niveau indiqué dans `config/languages.php`.
- Mettre à jour la date `updated` quand le contenu change vraiment (elle alimente `lastmod`). Une date dans le futur est refusée par le test.
- `php artisan test --filter=SeoPagesTest` vérifie : adresses uniques, titres et descriptions de bonne longueur et non dupliqués, un seul titre principal, données structurées valides, liens internes existants, jetons remplacés, aucun tiret cadratin. La page apparaît seule dans le plan du site, la page « Ressources », `llms.txt` ; ajouter son lien dans le pied de page si elle est importante.

## 6. Limites honnêtes

- Un site neuf met **plusieurs semaines** à apparaître, et des mois à bien se classer sur « chatbot WhatsApp » (beaucoup de concurrents). Les recherches locales et précises (ville + métier, « combien coûte », Mobile Money) sont plus faciles à gagner.
- Le nom **Kouma** est rare : la recherche de la marque elle-même sera vite gagnée ; les variantes (`kuma`, `couma`) dépendent de Google (il peut proposer « Essayez avec l'orthographe Kouma »).
- Les textes des pages pays parlent de langues locales avec leurs limites. À faire relire par des locuteurs avant toute campagne.
