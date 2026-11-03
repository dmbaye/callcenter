<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Help center
    |--------------------------------------------------------------------------
    |
    | Articles of the in-app help center (menu « Aide »), in reading order.
    | Each article is a Markdown file in resources/help/{slug}.md. In the
    | text, {team} is replaced with the reader's current team slug, so links
    | such as /{team}/campaigns open their own pages.
    |
    | audience: who the article is written for — agent, supervisor, admin
    | or all. Every article stays readable by everyone; the audience only
    | decides what the help center recommends first.
    |
    | modes: organizations it applies to (call_center, internal). Omitted =
    | both. An internal-only organization does not see call-center articles.
    |
    */

    'sections' => [
        'start' => 'Prise en main',
        'agent' => 'Agents',
        'supervision' => 'Supervision',
        'admin' => 'Administration',
    ],

    'articles' => [
        'communication-interne' => [
            'modes' => ['internal'],
            'section' => 'start',
            'audience' => 'all',
            'title' => 'Démarrer en communication interne',
            'summary' => 'Postes, annuaire, appels entre collègues et réunions vidéo.',
        ],
        'bienvenue' => [
            'modes' => ['call_center'],
            'section' => 'start',
            'audience' => 'all',
            'title' => 'Découvrir l\'application',
            'summary' => 'Les rôles, la navigation et la visite guidée.',
        ],
        'profil-et-preferences' => [
            'section' => 'start',
            'audience' => 'all',
            'title' => 'Votre profil et vos préférences',
            'summary' => 'Photo, langue français ou anglais, thème clair ou sombre, notifications et sécurité.',
        ],
        'mise-en-place' => [
            'modes' => ['call_center'],
            'section' => 'start',
            'audience' => 'admin',
            'title' => 'Mettre en place votre centre d\'appels',
            'summary' => 'Les étapes pour recevoir vos premiers appels, dans l\'ordre.',
        ],

        'softphone' => [
            'section' => 'agent',
            'audience' => 'agent',
            'title' => 'Le softphone',
            'summary' => 'Répondre, appeler, mettre en attente et transférer depuis le navigateur.',
        ],
        'statuts-agent' => [
            'modes' => ['call_center'],
            'section' => 'agent',
            'audience' => 'agent',
            'title' => 'Les statuts agent',
            'summary' => 'Prêt, Non prêt, Post-appel, Ne pas déranger, Déconnexion et raccourcis clavier.',
        ],
        'qualifier-un-appel' => [
            'modes' => ['call_center'],
            'section' => 'agent',
            'audience' => 'agent',
            'title' => 'Qualifier un appel',
            'summary' => 'Le résultat, les notes et les rappels après chaque appel.',
        ],
        'historique-des-appels' => [
            'section' => 'agent',
            'audience' => 'all',
            'title' => 'L\'historique des appels',
            'summary' => 'Retrouver un appel, réécouter un enregistrement, rappeler.',
        ],
        'poste-agent-campagnes' => [
            'modes' => ['call_center'],
            'section' => 'agent',
            'audience' => 'agent',
            'title' => 'Travailler une campagne',
            'summary' => 'Le poste agent : contact suivant, script, qualification et rappels.',
        ],
        'reunions-video' => [
            'section' => 'agent',
            'audience' => 'all',
            'title' => 'Les réunions vidéo',
            'summary' => 'Instantanées ou planifiées, durée selon l\'offre, invitations agenda, notes partagées et statut agent.',
        ],
        'planning' => [
            'section' => 'agent',
            'audience' => 'all',
            'title' => 'Planning, échanges, pointage et congés',
            'summary' => 'Roster hebdomadaire et mensuel, échanges de postes, feuilles de temps, congés et règles de repos.',
        ],

        'supervision-temps-reel' => [
            'modes' => ['call_center'],
            'section' => 'supervision',
            'audience' => 'supervisor',
            'title' => 'La supervision en temps réel',
            'summary' => 'Files, appels en attente, agents, écoute et chuchotement.',
        ],
        'journal-des-agents' => [
            'modes' => ['call_center'],
            'section' => 'supervision',
            'audience' => 'supervisor',
            'title' => 'Le journal des agents',
            'summary' => 'Le temps passé par statut et par motif de pause.',
        ],
        'campagnes' => [
            'modes' => ['call_center'],
            'section' => 'supervision',
            'audience' => 'supervisor',
            'title' => 'Créer et piloter une campagne',
            'summary' => 'Modes de numérotation, import des contacts, codes de qualification et liste « Ne pas appeler ».',
        ],

        'membres-et-roles' => [
            'section' => 'admin',
            'audience' => 'admin',
            'title' => 'Membres et rôles',
            'summary' => 'Créer un agent avec son poste, inviter, changer un rôle.',
        ],
        'files-d-attente' => [
            'modes' => ['call_center'],
            'section' => 'admin',
            'audience' => 'admin',
            'title' => 'Les files d\'attente',
            'summary' => 'Distribution des appels, agents, horaires, débordement et enquête.',
        ],
        'numeros-et-svi' => [
            'modes' => ['call_center'],
            'section' => 'admin',
            'audience' => 'admin',
            'title' => 'Numéros et menus vocaux (SVI)',
            'summary' => 'Où arrive chaque numéro, et le menu « tapez 1, tapez 2 ».',
        ],
        'postes-sip' => [
            'section' => 'admin',
            'audience' => 'admin',
            'title' => 'Postes SIP et plan d\'appel',
            'summary' => 'Les réglages téléphoniques avancés.',
        ],
        'motifs-de-pause' => [
            'modes' => ['call_center'],
            'section' => 'admin',
            'audience' => 'admin',
            'title' => 'Les motifs de pause',
            'summary' => 'Les raisons proposées dans le menu « Non prêt ».',
        ],
        'organisation-et-abonnement' => [
            'section' => 'admin',
            'audience' => 'admin',
            'title' => 'Organisation et abonnement',
            'summary' => 'Le profil de l\'entreprise, les formules et le paiement.',
        ],
    ],
];
