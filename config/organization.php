<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Organization profile options
    |--------------------------------------------------------------------------
    |
    | Choices offered on the organization page (Paramètres → Organisation).
    | Keys are stored in the teams table, labels are shown to the customer.
    | Used internally for segmentation, marketing and sales follow-up: add
    | a key to offer a new choice, never rename an existing one.
    |
    */

    'industries' => [
        'banking' => 'Banque et microfinance',
        'insurance' => 'Assurance',
        'telecom' => 'Télécommunications',
        'mobile_money' => 'Mobile money et paiement',
        'retail' => 'Commerce et distribution',
        'ecommerce' => 'E-commerce',
        'health' => 'Santé et pharmacie',
        'education' => 'Éducation et formation',
        'transport' => 'Transport et logistique',
        'hospitality' => 'Hôtellerie, restauration et tourisme',
        'real_estate' => 'Immobilier et BTP',
        'energy' => 'Énergie et eau',
        'agriculture' => 'Agriculture et agro-industrie',
        'mining' => 'Mines',
        'industry' => 'Industrie',
        'media' => 'Médias et communication',
        'business_services' => 'Services aux entreprises',
        'bpo' => 'Centre d\'appels et externalisation',
        'public' => 'Administration publique',
        'ngo' => 'ONG et organisations internationales',
        'other' => 'Autre',
    ],

    'sizes' => [
        '1-10' => '1 à 10 employés',
        '11-50' => '11 à 50 employés',
        '51-200' => '51 à 200 employés',
        '201-500' => '201 à 500 employés',
        '501-1000' => '501 à 1 000 employés',
        '1000+' => 'Plus de 1 000 employés',
    ],

    'use_cases' => [
        'customer_service' => 'Service client',
        'telesales' => 'Télévente et prospection',
        'technical_support' => 'Support technique',
        'collections' => 'Recouvrement',
        'appointments' => 'Prise de rendez-vous',
        'surveys' => 'Enquêtes et sondages',
        'hotline' => 'Standard et accueil téléphonique',
        'other' => 'Autre',
    ],

    'acquisition_sources' => [
        'word_of_mouth' => 'Bouche-à-oreille',
        'facebook' => 'Facebook',
        'linkedin' => 'LinkedIn',
        'whatsapp' => 'WhatsApp',
        'search' => 'Recherche Google',
        'event' => 'Salon ou événement',
        'partner' => 'Partenaire ou revendeur',
        'sales' => 'Démarchage commercial',
        'press' => 'Presse, radio ou TV',
        'other' => 'Autre',
    ],

    // ISO 3166-1 alpha-2, the region first.
    'countries' => [
        'ML' => 'Mali',
        'SN' => 'Sénégal',
        'CI' => 'Côte d\'Ivoire',
        'BF' => 'Burkina Faso',
        'NE' => 'Niger',
        'GN' => 'Guinée',
        'MR' => 'Mauritanie',
        'TG' => 'Togo',
        'BJ' => 'Bénin',
        'GH' => 'Ghana',
        'NG' => 'Nigeria',
        'CM' => 'Cameroun',
        'MA' => 'Maroc',
        'FR' => 'France',
        'other' => 'Autre pays',
    ],

    'default_country' => 'ML',
];
