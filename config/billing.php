<?php

/*
|--------------------------------------------------------------------------
| Subscriptions
|--------------------------------------------------------------------------
|
| Plans are prepaid periods paid through a hosted checkout: PayDunya by
| default (Orange Money Mali, Moov Money Mali, cards), CinetPay as an option
| for countries where it accepts merchants (BILLING_GATEWAY=cinetpay).
| Mobile money cannot be debited automatically, so a period is bought,
| a reminder goes out before it ends, a grace period follows and the team
| then becomes read-only until the next payment.
|
| Limits: null means unlimited. Prices are in XOF and multiples of 5
| (mobile money providers reject other amounts).
|
| outbound_minutes: outbound call minutes bundled in the plan each month.
| 0 = outbound calls are not included and are billed separately per minute.
|
| recording_days: how long call recordings are kept (feature call_recording).
| The `recording` feature is the recording of video meetings.
|
| monthly_price 0 = free plan: chosen without payment, it never expires
| (no reminder, no grace period, no read-only), extra agents cannot be bought.
| `monitoring` gates live supervision and the agent log.
|
*/

return [

    'currency' => 'XOF',

    // Free trial given once to every new team.
    'trial_plan' => env('BILLING_TRIAL_PLAN', 'essentiel'),
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    // Reminder before the end of a period, then grace before read-only.
    'reminder_days' => 7,
    'grace_days' => 5,

    // Annual billing: pay this many months for 12.
    'annual_months_charged' => 10,

    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'tagline' => 'Pour structurer vos premiers appels et quitter les téléphones perso.',
            'monthly_price' => 10000,
            'highlighted' => false,
            'limits' => [
                'agents' => 2,
                'supervisors' => 1,
                'queues' => 1,
                'history_months' => 1,
                'meeting_participants' => 3,
                'meeting_minutes' => 20,
                'outbound_minutes' => 0,
                'recording_days' => 0,
            ],
            'extra_agent_price' => null,
            'features' => [
                'monitoring' => false,
                'campaigns' => false,
                'video' => true,
                // Meeting video: hd = 720p camera and screen sharing, full_hd = 1080p
                // (neither: 360p camera, 720p screen sharing at 5 frames/s).
                'hd_video' => false,
                'full_hd_video' => false,
                'recording' => false,
                'call_recording' => false,
                'web_widget' => false,
            ],
            'support' => 'Communauté / Documentation',
        ],
        'essentiel' => [
            'name' => 'Essentiel',
            'tagline' => 'Pour les petites équipes qui quittent les téléphones partagés.',
            'monthly_price' => 45000,
            'highlighted' => false,
            'limits' => [
                'agents' => 5,
                'supervisors' => 1,
                'queues' => 2,
                'history_months' => 3,
                // Video like Zoom's free plan: small meetings capped at 40 minutes.
                'meeting_participants' => 5,
                'meeting_minutes' => 40,
                'outbound_minutes' => 0,
                'recording_days' => 0,
            ],
            'extra_agent_price' => null,
            'features' => [
                'monitoring' => true,
                'campaigns' => false,
                'video' => true,
                // Meeting video: hd = 720p camera and screen sharing, full_hd = 1080p
                // (neither: 360p camera, 720p screen sharing at 5 frames/s).
                'hd_video' => false,
                'full_hd_video' => false,
                'recording' => false,
                'call_recording' => false,
                'web_widget' => false,
            ],
            'support' => 'E-mail',
        ],

        'croissance' => [
            'name' => 'Croissance',
            'tagline' => 'Pour les équipes en développement ayant besoin de souplesse.',
            'monthly_price' => 85000,
            'highlighted' => true,
            'limits' => [
                'agents' => 10,
                'supervisors' => 2,
                'queues' => 5,
                'history_months' => 6,
                'meeting_participants' => 8,
                'meeting_minutes' => 120,
                'outbound_minutes' => 0,
                'recording_days' => 30,
            ],
            'extra_agent_price' => 8500,
            'features' => [
                'monitoring' => true,
                'campaigns' => false,
                'video' => true,
                // Meeting video: hd = 720p camera and screen sharing, full_hd = 1080p
                // (neither: 360p camera, 720p screen sharing at 5 frames/s).
                'hd_video' => true,
                'full_hd_video' => false,
                'recording' => false,
                'call_recording' => true, // Enables call recording for sales teams
                'web_widget' => false,
            ],
            'support' => 'WhatsApp, heures ouvrables',
        ],

        'pro' => [
            'name' => 'Pro',
            'tagline' => 'Pour les équipes ventes et support qui gèrent du volume au quotidien.',
            'monthly_price' => 150000,
            'highlighted' => false,
            'limits' => [
                'agents' => 20,
                'supervisors' => 3,
                'queues' => 10,
                'history_months' => 12,
                'meeting_participants' => 10,
                // Longest meeting, in minutes: the room closes when it is reached.
                'meeting_minutes' => 240,
                'outbound_minutes' => 0,
                'recording_days' => 90,
            ],
            'extra_agent_price' => 8500,
            'features' => [
                'monitoring' => true,
                'campaigns' => true,
                'video' => true,
                // Meeting video: hd = 720p camera and screen sharing, full_hd = 1080p
                // (neither: 360p camera, 720p screen sharing at 5 frames/s).
                'hd_video' => true,
                'full_hd_video' => false,
                'recording' => false,
                'call_recording' => true,
                'web_widget' => false,
            ],
            'support' => 'WhatsApp, heures ouvrables',
        ],

        'entreprise' => [
            'name' => 'Corporate',
            'tagline' => 'Pour les grands centres de contact, banques et institutions.',
            'monthly_price' => 450000,
            'highlighted' => false,
            'limits' => [
                'agents' => 50,
                'supervisors' => null,
                'queues' => null,
                'history_months' => 24,
                'meeting_participants' => 50,
                // A full day: only a guard against rooms left open.
                'meeting_minutes' => 1440,
                'outbound_minutes' => 0,
                'recording_days' => 365,
            ],
            // Monthly price of each agent above the included ones.
            'extra_agent_price' => 10000,
            'features' => [
                'monitoring' => true,
                'campaigns' => true,
                'video' => true,
                // Meeting video: hd = 720p camera and screen sharing, full_hd = 1080p
                // (neither: 360p camera, 720p screen sharing at 5 frames/s).
                'hd_video' => true,
                'full_hd_video' => true,
                'recording' => true,
                'call_recording' => true,
                'web_widget' => true,
            ],
            'support' => 'Prioritaire + accompagnement',
        ],
    ],

    // Provider for new checkouts: paydunya | cinetpay. Payments keep the one they were made with.
    'gateway' => env('BILLING_GATEWAY', 'paydunya'),

    'paydunya' => [
        // test = sandbox API (sandbox keys), live = real payments.
        'mode' => env('PAYDUNYA_MODE', 'test'),
        'master_key' => env('PAYDUNYA_MASTER_KEY'),
        'private_key' => env('PAYDUNYA_PRIVATE_KEY'),
        'token' => env('PAYDUNYA_TOKEN'),
        // Shown on the PayDunya payment page.
        'store_name' => env('PAYDUNYA_STORE_NAME', env('APP_NAME', 'Callcenter')),
        'logo_url' => env('PAYDUNYA_LOGO_URL'),
        // Comma-separated PayDunya channel names to offer (empty = every method enabled on the account).
        'channels' => env('PAYDUNYA_CHANNELS', ''),
        'timeout' => 15,
    ],

    'cinetpay' => [
        'api_url' => env('CINETPAY_API_URL', 'https://api-checkout.cinetpay.com/v2'),
        'api_key' => env('CINETPAY_API_KEY'),
        'site_id' => env('CINETPAY_SITE_ID'),
        'secret_key' => env('CINETPAY_SECRET_KEY'),
        // ALL, MOBILE_MONEY, CREDIT_CARD or WALLET
        'channels' => env('CINETPAY_CHANNELS', 'ALL'),
        'lang' => 'fr',
        'timeout' => 15,
        // Defaults used for the card form when the profile has no address.
        'customer_country' => env('CINETPAY_CUSTOMER_COUNTRY', 'ML'),
        'customer_city' => env('CINETPAY_CUSTOMER_CITY', 'Bamako'),
    ],

];
