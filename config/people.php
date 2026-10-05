<?php

/*
| Suivi des inscrits : envois de l'équipe (voir docs/PEOPLE.md).
*/
return [
    // E-mails de l'équipe aux inscrits, par jour (tous les envois comptent). Protège la réputation d'un domaine récent :
    // un domaine qui envoie soudain beaucoup de messages est classé en indésirables. À monter peu à peu.
    'daily_email_cap' => (int) env('PEOPLE_DAILY_EMAIL_CAP', 40),

    // Personnes jointes par clic dans un envoi groupé.
    'batch' => (int) env('PEOPLE_BATCH', 25),

    // Une personne déjà contactée depuis moins de ce nombre de jours n'est pas reprise par un envoi groupé.
    'cooldown_days' => (int) env('PEOPLE_COOLDOWN_DAYS', 7),
];
