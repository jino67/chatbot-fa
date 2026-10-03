<?php

/*
| Connexion avec Google, Apple, Microsoft ou Facebook. Les identifiants se saisissent dans l'administration
| (Paramètres, « Connexion avec Google, Apple... ») ; ces variables d'environnement servent de valeurs de secours.
| Facebook réutilise l'application Meta déjà déclarée pour les pages (voir config/platform.php, « facebook »).
*/
return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
    ],
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],
];
