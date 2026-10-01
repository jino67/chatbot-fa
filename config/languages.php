<?php

/*
| Langues que l'assistant peut parler. Chaque langue a un niveau de fiabilite, dit franchement au client :
|  - native       : le modele de langage l'ecrit et la comprend tres bien ;
|  - good         : bon niveau, quelques maladresses possibles ;
|  - assisted     : langue locale, comprise en partie : phrases courtes, chiffres et noms repris tels quels ;
|  - experimental : tres peu de donnees d'entrainement, a tester avec de vraies phrases avant de s'engager.
| stt = ecoute des messages vocaux ; tts = reponse en audio. « local » : necessite un serveur de reconnaissance
| vocale libre (voir docs/LANGUES.md) ; sans lui, le vocal dans cette langue n'est pas propose.
*/
return [
    'fr' => ['name' => 'Français', 'native' => 'Français', 'tier' => 'native', 'rtl' => false, 'iso' => 'fr', 'stt' => 'openai', 'tts' => true],
    'en' => ['name' => 'Anglais', 'native' => 'English', 'tier' => 'native', 'rtl' => false, 'iso' => 'en', 'stt' => 'openai', 'tts' => true],
    'ar' => ['name' => 'Arabe', 'native' => 'العربية', 'tier' => 'native', 'rtl' => true, 'iso' => 'ar', 'stt' => 'openai', 'tts' => true],
    'sw' => ['name' => 'Swahili', 'native' => 'Kiswahili', 'tier' => 'good', 'rtl' => false, 'iso' => 'sw', 'stt' => 'openai', 'tts' => false],
    'bm' => ['name' => 'Bambara', 'native' => 'Bamanankan', 'tier' => 'assisted', 'rtl' => false, 'iso' => 'bm', 'stt' => 'local', 'tts' => false],
    'dyu' => ['name' => 'Dioula', 'native' => 'Julakan', 'tier' => 'assisted', 'rtl' => false, 'iso' => 'dyu', 'stt' => 'local', 'tts' => false],
    'ff' => ['name' => 'Peul', 'native' => 'Fulfulde', 'tier' => 'assisted', 'rtl' => false, 'iso' => 'ff', 'stt' => 'local', 'tts' => false],
    'wo' => ['name' => 'Wolof', 'native' => 'Wolof', 'tier' => 'assisted', 'rtl' => false, 'iso' => 'wo', 'stt' => 'local', 'tts' => false],
    'mos' => ['name' => 'Mooré', 'native' => 'Mooré', 'tier' => 'experimental', 'rtl' => false, 'iso' => 'mos', 'stt' => 'local', 'tts' => false],
    'zdj' => ['name' => 'Shikomori', 'native' => 'Shikomori', 'tier' => 'experimental', 'rtl' => false, 'iso' => 'zdj', 'stt' => null, 'tts' => false],

    // Libelles des niveaux, affiches dans les reglages de l'assistant.
    'tiers' => [
        'native' => ['label' => 'Très bon', 'tone' => 'green', 'help' => 'L\'assistant l\'écrit et la comprend très bien.'],
        'good' => ['label' => 'Bon', 'tone' => 'green', 'help' => 'Bon niveau, quelques maladresses possibles.'],
        'assisted' => ['label' => 'Assisté', 'tone' => 'amber', 'help' => 'Langue locale comprise en partie : phrases courtes, prix et chiffres repris tels quels dans vos documents. Testez avec de vraies phrases.'],
        'experimental' => ['label' => 'Expérimental', 'tone' => 'red', 'help' => 'Très peu de données existent pour cette langue : à tester avant de vous engager. L\'assistant répond aussi en français.'],
    ],
];
