<?php

return [
    /*
    | "Non prêt" reasons every new team starts with. Admins edit them in
    | Paramètres › Motifs de pause.
    */
    'default_pause_reasons' => [
        'Débriefing',
        'Déjeuner',
        'Formation',
        'Pause',
        'Pause prière',
        'Pause toilette',
        'Petit-déjeuner',
        'Réunion',
    ],

    // Most reasons a team can define.
    'max_pause_reasons' => 30,
];
