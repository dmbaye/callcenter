<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Video meetings
    |--------------------------------------------------------------------------
    |
    | Times in e-mails are written in this zone (calendars convert the
    | attached invitation to each attendee's own zone).
    |
    */

    'timezone' => env('MEETINGS_TIMEZONE', 'Africa/Bamako'),

    // In-app reminder this many minutes before a planned meeting.
    'reminder_minutes' => (int) env('MEETINGS_REMINDER_MINUTES', 10),

    // "Rejoindre" is highlighted from this many minutes before the start.
    'join_early_minutes' => 10,

    'durations' => [15, 30, 45, 60, 90, 120, 180],
];
