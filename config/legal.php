<?php

/*
|--------------------------------------------------------------------------
| Legal pages
|--------------------------------------------------------------------------
|
| Identity shown on /mentions-legales, /confidentialite and /confiance.
| Empty values are shown as « [à compléter] » on the pages, so a draft can
| never look final by accident. Have the texts reviewed by a lawyer before
| publishing them.
|
*/

return [
    // The date shown as "Dernière mise à jour" on the three pages.
    'updated_at' => env('LEGAL_UPDATED_AT', '2026-10-31'),

    'company' => [
        'name' => env('LEGAL_COMPANY_NAME', 'MbayeLab'),
        // e.g. SARL, SA, entreprise individuelle
        'form' => env('LEGAL_COMPANY_FORM'),
        'capital' => env('LEGAL_COMPANY_CAPITAL'),
        'address' => env('LEGAL_COMPANY_ADDRESS', 'Moribabougou, Bamako, Mali'),
        'rccm' => env('LEGAL_RCCM'),
        'nif' => env('LEGAL_NIF'),
        'phone' => env('LEGAL_PHONE'),
        'email' => env('LEGAL_EMAIL', env('CONTACT_EMAIL')),
        'publication_director' => env('LEGAL_PUBLICATION_DIRECTOR'),
    ],

    // Contacts for data-protection requests and security reports.
    'privacy_email' => env('PRIVACY_EMAIL', env('LEGAL_EMAIL', env('CONTACT_EMAIL'))),
    'security_email' => env('SECURITY_EMAIL', env('LEGAL_EMAIL', env('CONTACT_EMAIL'))),

    // Receipt number of the declaration made to the APDP (Autorité de protection
    // des données à caractère personnel, Loi n° 2013-015 du 21 mai 2013).
    'apdp_reference' => env('LEGAL_APDP_REFERENCE'),

    'hosting' => [
        'name' => env('LEGAL_HOST_NAME', 'SMTD'),
        'address' => env('LEGAL_HOST_ADDRESS', 'Bamako, Mali'),
        'description' => env('LEGAL_HOST_DESCRIPTION', 'Hébergement en salle serveur (colocation), au Mali'),
    ],

    // Sub-processors listed on the privacy and trust pages.
    'subprocessors' => [
        ['name' => env('LEGAL_HOST_NAME', 'SMTD'), 'role' => 'Hébergement des serveurs (application, téléphonie, vidéo)', 'country' => 'Mali'],
        ['name' => 'Orange Mali', 'role' => 'Opérateur téléphonique : acheminement des appels vers et depuis le réseau public', 'country' => 'Mali'],
        ['name' => 'PayDunya', 'role' => 'Paiement des abonnements (Mobile Money, carte)', 'country' => 'Sénégal'],
        ['name' => env('LEGAL_EMAIL_PROVIDER'), 'role' => 'Envoi des e-mails (invitations, rappels, factures)', 'country' => env('LEGAL_EMAIL_PROVIDER_COUNTRY')],
    ],
];
