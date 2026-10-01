<?php

/*
|--------------------------------------------------------------------------
| Mesure d'audience (voir docs/ANALYTICS.md et App\Services\Analytics)
|--------------------------------------------------------------------------
|
| Réglages de la plateforme : « analytics.enabled » et « analytics.retention_days » se changent dans Administration,
| Paramètres, Statistiques. Les listes ci-dessous classent la provenance des visites et estiment le pays sans adresse IP.
*/
return [

    // Fuseau dans lequel on lit les heures d'affluence : celui de la plateforme (UTC = Ouagadougou, Abidjan, Dakar, Bamako).
    // Vide : le fuseau de l'application (config « app.timezone »).
    'timezone' => env('ANALYTICS_TIMEZONE'),

    // Durée de conservation par défaut, en jours (13 mois et un peu plus).
    'retention_days' => 400,

    // Une visite se termine après autant de minutes sans geste (côté navigateur).
    'session_minutes' => 30,

    // Navigateurs automatiques : jamais comptés.
    'bots' => '/bot|crawl|spider|slurp|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python-requests|httpclient|facebookexternalhit|preview|scanner|phantom|selenium|puppeteer|playwright/i',

    // Provenance : on regarde d'abord les assistants d'IA, puis les moteurs, puis les réseaux sociaux.
    'ai_hosts' => ['chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'copilot.microsoft.com', 'you.com', 'mistral.ai', 'chat.mistral.ai'],
    'search_hosts' => ['google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'qwant.com', 'ecosia.org', 'yandex.', 'baidu.com', 'search.brave.com'],
    'social_hosts' => ['facebook.com', 'fb.com', 'fb.me', 'm.facebook.com', 'l.facebook.com', 'instagram.com', 'l.instagram.com', 'twitter.com', 't.co', 'x.com', 'linkedin.com', 'lnkd.in', 'tiktok.com', 'youtube.com', 'youtu.be', 'whatsapp.com', 'wa.me', 't.me', 'telegram.org', 'pinterest.', 'snapchat.com', 'reddit.com'],

    // Pays estimé d'après le fuseau horaire du navigateur (aucune adresse IP n'est lue ni gardée).
    'timezones' => [
        'Africa/Ouagadougou' => 'BF', 'Africa/Abidjan' => 'CI', 'Africa/Dakar' => 'SN', 'Africa/Bamako' => 'ML', 'Africa/Niamey' => 'NE',
        'Africa/Lome' => 'TG', 'Africa/Porto-Novo' => 'BJ', 'Africa/Conakry' => 'GN', 'Africa/Nouakchott' => 'MR', 'Africa/Banjul' => 'GM',
        'Africa/Bissau' => 'GW', 'Africa/Freetown' => 'SL', 'Africa/Monrovia' => 'LR', 'Africa/Accra' => 'GH', 'Africa/Lagos' => 'NG',
        'Africa/Douala' => 'CM', 'Africa/Libreville' => 'GA', 'Africa/Brazzaville' => 'CG', 'Africa/Kinshasa' => 'CD', 'Africa/Lubumbashi' => 'CD',
        'Africa/Bangui' => 'CF', 'Africa/Ndjamena' => 'TD', 'Africa/Malabo' => 'GQ', 'Africa/Luanda' => 'AO', 'Africa/Kigali' => 'RW',
        'Africa/Bujumbura' => 'BI', 'Africa/Nairobi' => 'KE', 'Africa/Dar_es_Salaam' => 'TZ', 'Africa/Kampala' => 'UG', 'Africa/Addis_Ababa' => 'ET',
        'Africa/Djibouti' => 'DJ', 'Africa/Johannesburg' => 'ZA', 'Africa/Maputo' => 'MZ', 'Africa/Lusaka' => 'ZM', 'Africa/Harare' => 'ZW',
        'Africa/Casablanca' => 'MA', 'Africa/Algiers' => 'DZ', 'Africa/Tunis' => 'TN', 'Africa/Tripoli' => 'LY', 'Africa/Cairo' => 'EG',
        'Indian/Comoro' => 'KM', 'Indian/Antananarivo' => 'MG', 'Indian/Mauritius' => 'MU', 'Indian/Reunion' => 'RE', 'Indian/Mayotte' => 'YT',
        'Europe/Paris' => 'FR', 'Europe/Brussels' => 'BE', 'Europe/Zurich' => 'CH', 'Europe/London' => 'GB', 'Europe/Madrid' => 'ES',
        'Europe/Berlin' => 'DE', 'Europe/Rome' => 'IT', 'Europe/Lisbon' => 'PT', 'America/Montreal' => 'CA', 'America/Toronto' => 'CA',
        'America/New_York' => 'US', 'America/Chicago' => 'US', 'America/Los_Angeles' => 'US', 'Asia/Dubai' => 'AE', 'Asia/Riyadh' => 'SA',
    ],

    // Noms de pays pour l'affichage (les codes inconnus s'affichent tels quels).
    'countries' => [
        'BF' => 'Burkina Faso', 'CI' => "Côte d'Ivoire", 'SN' => 'Sénégal', 'ML' => 'Mali', 'NE' => 'Niger', 'TG' => 'Togo', 'BJ' => 'Bénin',
        'GN' => 'Guinée', 'MR' => 'Mauritanie', 'GM' => 'Gambie', 'GW' => 'Guinée-Bissau', 'SL' => 'Sierra Leone', 'LR' => 'Liberia', 'GH' => 'Ghana',
        'NG' => 'Nigeria', 'CM' => 'Cameroun', 'GA' => 'Gabon', 'CG' => 'Congo', 'CD' => 'RD Congo', 'CF' => 'Centrafrique', 'TD' => 'Tchad',
        'GQ' => 'Guinée équatoriale', 'AO' => 'Angola', 'RW' => 'Rwanda', 'BI' => 'Burundi', 'KE' => 'Kenya', 'TZ' => 'Tanzanie', 'UG' => 'Ouganda',
        'ET' => 'Éthiopie', 'DJ' => 'Djibouti', 'ZA' => 'Afrique du Sud', 'MZ' => 'Mozambique', 'ZM' => 'Zambie', 'ZW' => 'Zimbabwe', 'MA' => 'Maroc',
        'DZ' => 'Algérie', 'TN' => 'Tunisie', 'LY' => 'Libye', 'EG' => 'Égypte', 'KM' => 'Comores', 'MG' => 'Madagascar', 'MU' => 'Maurice',
        'RE' => 'La Réunion', 'YT' => 'Mayotte', 'FR' => 'France', 'BE' => 'Belgique', 'CH' => 'Suisse', 'GB' => 'Royaume-Uni', 'ES' => 'Espagne',
        'DE' => 'Allemagne', 'IT' => 'Italie', 'PT' => 'Portugal', 'CA' => 'Canada', 'US' => 'États-Unis', 'AE' => 'Émirats arabes unis', 'SA' => 'Arabie saoudite',
    ],

    // Actions mesurées côté serveur : nom de route (ou « MÉTHODE chemin » pour une route sans nom) => nom de l'action.
    // Une action n'est enregistrée que si la requête a réussi (voir App\Http\Middleware\RecordActions).
    'actions' => [
        'POST register' => 'registered',
        'bots.store' => 'bot.created',
        'bots.update' => 'bot.updated',
        'sources.store' => 'source.added',
        'playground.ask' => 'playground.used',
        'instructions.update' => 'instructions.saved',
        'instructions.profile' => 'instructions.saved',
        'conversations.reply' => 'conversation.replied',
        'conversations.status' => 'conversation.status',
        'leads.update' => 'lead.updated',
        'alerts.update' => 'alerts.saved',
        'alerts.template' => 'alerts.saved',
        'templates.add' => 'templates.added',
        'templates.pack' => 'templates.added',
        'templates.store' => 'templates.added',
        'import.commit' => 'import.committed',
        'channels.whatsapp-request' => 'channel.requested',
        'billing.request' => 'plan.requested',
        'api-keys.store' => 'api_key.created',
        'currency.update' => 'currency.changed',
        'pwa.installed' => 'pwa.installed',
        'push.subscribe' => 'push.enabled',
        'notifications.preferences.update' => 'notifications.preferences',
    ],

    // Fonctionnalités de l'application : quelles actions comptent pour quelle fonctionnalité (adoption par les clients).
    'features' => [
        'sources' => ['label' => 'Connaissances (documents, sites)', 'actions' => ['source.added']],
        'playground' => ['label' => 'Zone de test', 'actions' => ['playground.used']],
        'conversations' => ['label' => 'Réponses aux clients', 'actions' => ['conversation.replied', 'conversation.status']],
        'leads' => ['label' => 'Demandes à traiter', 'actions' => ['lead.updated']],
        'alerts' => ['label' => 'Alertes', 'actions' => ['alerts.saved']],
        'templates' => ['label' => 'Modèles WhatsApp', 'actions' => ['templates.added', 'template.sent']],
        'settings' => ['label' => 'Réglages et personnalité', 'actions' => ['bot.updated', 'instructions.saved']],
        'import' => ['label' => 'Import WhatsApp', 'actions' => ['import.committed']],
        'api' => ['label' => 'API développeur', 'actions' => ['api_key.created']],
        'pwa' => ['label' => 'Application installée', 'actions' => ['pwa.installed']],
        'notifications' => ['label' => 'Notifications sur le téléphone', 'actions' => ['push.enabled', 'notifications.preferences']],
    ],
];
