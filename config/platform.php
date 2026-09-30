<?php

/*
|--------------------------------------------------------------------------
| Configuration de la plateforme
|--------------------------------------------------------------------------
| Ce fichier ne porte que des valeurs par defaut et des secrets d'amorcage.
| Les offres, les fournisseurs IA, la marque et les embeddings se modifient
| depuis le tableau de bord du super admin (ils vivent en base de donnees).
| Sans aucune cle, la plateforme tourne hors ligne (reponses extractives,
| vecteurs locaux) : suffisant pour une demonstration, pas pour la production.
*/

return [

    // Adresse notifiee des demandes clients et des bascules de fournisseur IA.
    'admin_email' => env('PLATFORM_ADMIN_EMAIL'),

    // Compte super admin cree a l'installation (php artisan migrate --seed).
    // Sans mot de passe fourni, il est genere et affiche une seule fois.
    'admin_name' => env('PLATFORM_ADMIN_NAME', 'Super admin'),
    'admin_password' => env('PLATFORM_ADMIN_PASSWORD'),

    'ai' => [
        // Force le mode hors ligne meme si des cles existent (tests, demonstrations).
        'offline' => env('PLATFORM_LLM') === 'fake',

        // Cles d'amorcage par fournisseur : utilisees quand aucune cle n'est saisie dans le tableau de bord.
        'env_keys' => [
            'anthropic' => env('ANTHROPIC_API_KEY'),
            'openai' => env('OPENAI_API_KEY'),
            'openrouter' => env('OPENROUTER_API_KEY'),
            'deepinfra' => env('DEEPINFRA_API_KEY'),
            'together' => env('TOGETHER_API_KEY'),
            'custom' => env('CUSTOM_LLM_API_KEY'),
        ],

        // Profondeur de raisonnement (ignoree par les modeles qui ne la supportent pas, dont Haiku 4.5).
        'effort' => env('PLATFORM_EFFORT', 'low'),
        'max_tokens' => (int) env('PLATFORM_MAX_TOKENS', 900),
        'timeout' => (int) env('PLATFORM_LLM_TIMEOUT', 45),

        // Embeddings : valeurs par defaut, surchargees par les parametres du tableau de bord.
        'embeddings' => env('PLATFORM_EMBEDDINGS', env('VOYAGE_API_KEY') ? 'voyage' : (env('OPENAI_API_KEY') ? 'openai' : 'hashing')),
        'voyage' => [
            'api_key' => env('VOYAGE_API_KEY'),
            'model' => env('VOYAGE_MODEL', 'voyage-3.5'),
            'base_url' => env('VOYAGE_BASE_URL', 'https://api.voyageai.com/v1'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],

        // Disjoncteur : duree pendant laquelle un fournisseur en echec est evite, selon la cause (secondes).
        'cooldown' => ['billing' => 600, 'auth' => 3600, 'rate_limit' => 60, 'overloaded' => 45, 'network' => 30, 'unsupported' => 3600, 'unknown' => 60],
    ],

    // Valeurs par defaut des parametres modifiables (cle => valeur), voir App\Services\PlatformSettings.
    'settings' => [
        'ai.mode' => 'auto',              // auto : chaine de bascule ; forced : un fournisseur epingle
        'ai.strict_forced' => false,      // true : jamais de bascule automatique quand un fournisseur est epingle
        'embeddings.driver' => null,
        'billing.currency' => 'XOF',
        'billing.instructions' => 'Paiement par Orange Money, Moov Money ou virement. Envoyez la référence de la transaction à notre équipe : votre offre est activée dès réception.',
    ],

    'rag' => [
        'chunk_chars' => 1200,
        'overlap_chars' => 150,
        'top_k' => 6,
        // Score hybride minimal pour considerer qu'un extrait est pertinent.
        // A relever (0.30 a 0.40) avec de vrais embeddings (voyage, openai).
        'min_score' => (float) env('PLATFORM_MIN_SCORE', 0.15),
        // Poids du score vectoriel dans le score hybride (le reste va au score lexical BM25).
        'alpha' => 0.65,
        'history_messages' => 8,
        'max_context_chars' => 9000,
        'max_message_chars' => 1500,
        'embed_batch' => 32,
    ],

    'crawler' => [
        'user_agent' => env('PLATFORM_CRAWLER_UA', 'KoumaBot/1.0'),
        'timeout' => 12,
        'max_bytes' => 2_000_000,
        'delay_ms' => 250,
        'max_depth' => 2,
        'respect_robots' => true,
        // Reseaux sociaux : jamais crawles (conditions d'utilisation de Meta), import assiste uniquement.
        'blocked_hosts' => ['facebook.com', 'fb.com', 'fb.me', 'instagram.com', 'x.com', 'twitter.com', 'tiktok.com', 'linkedin.com'],
    ],

    'uploads' => [
        'disk' => 'local',
        'max_kb' => 20480,
        'documents' => ['pdf', 'docx', 'txt', 'md', 'csv', 'html', 'htm'],
        'images' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    ],

    'whatsapp' => [
        // Fenetre de service : reponse libre autorisee 24 h apres le dernier message du client.
        'session_window_hours' => 24,

        'meta' => [
            'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
            'app_secret' => env('META_APP_SECRET'),
            'verify_token' => env('META_VERIFY_TOKEN'),
        ],

        'twilio' => [
            // A renseigner si l'application est derriere un proxy qui masque l'URL publique.
            'public_base_url' => env('TWILIO_WEBHOOK_BASE_URL'),
        ],
    ],

    // Connexion officielle d'une page Facebook (API Graph). Desactivee tant que l'application Meta n'est pas configuree.
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        'scopes' => ['pages_show_list', 'pages_read_engagement'],
    ],

    'widget' => [
        'rate_per_minute_ip' => 30,
        'rate_per_minute_bot' => 300,
    ],

    // Periode de grace apres l'echeance d'un abonnement avant retour a l'offre gratuite (jours).
    'billing' => [
        'grace_days' => 3,
        'reminder_days' => 5,
    ],
];
