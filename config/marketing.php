<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Marketing site
    |--------------------------------------------------------------------------
    |
    | Public landing, features, pricing and contact pages (/marketing routes).
    |
    */

    'contact_email' => env('CONTACT_EMAIL', env('MAIL_FROM_ADDRESS', 'hello@example.com')),

    // Industry pages, /solutions/{slug}. Their content lives in resources/js/data/solutions.ts.
    'solutions' => ['banks', 'microfinance', 'healthcare', 'hospitality', 'insurance', 'retail'],
];
