<?php

/*
| Identite provisoire de la plateforme. Le nom, l'accroche et les contacts se modifient
| depuis le tableau de bord (Paramètres de la plateforme) sans toucher au code.
*/
return [
    'name' => 'Kouma',
    'tagline' => 'Le chatbot WhatsApp qui répond à vos clients à toute heure',
    // Anciennes accroches : une valeur enregistrée dans les Paramètres qui reprend l'une d'elles est remplacée par la
    // nouvelle (le formulaire de Paramètres a pu l'enregistrer telle quelle, sans que personne ne l'ait choisie).
    'legacy_taglines' => [
        "L'assistant qui répond à vos clients, sur votre site et sur WhatsApp",
    ],
    // « kuma » signifie « la parole » en bambara et en dioula.
    'meaning' => 'kouma vient de « kuma », la parole en bambara et en dioula',
    // Autres graphies sous lesquelles on cherchera la marque. Reprises dans la page d'accueil (donnees structurees,
    // question « Comment s'ecrit... », pied de page) seulement tant que le nom de la marque reste celui d'origine.
    'aliases' => ['Kuma', 'Couma', 'Cuma', 'Koumah'],
    // Contacts commerciaux de secours : les Paramètres de la plateforme (admin) restent prioritaires. Tant qu'aucun
    // numéro n'est défini, le bouton « Écrivez-nous sur WhatsApp » du site ne s'affiche pas.
    'whatsapp' => env('BRAND_WHATSAPP', ''),
    'email' => env('BRAND_EMAIL', ''),
    'primary' => '#2340D9',
    'accent' => '#FFB400',
];
