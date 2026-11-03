<?php

/*
|--------------------------------------------------------------------------
| Workforce planning
|--------------------------------------------------------------------------
|
| Defaults of every team. Each team changes its own shifts, rules and leave
| types on the Planning → Réglages page.
|
| Times are wall-clock times in `timezone`. A shift whose end is not after
| its start (16:00 – 00:00) ends the next day.
|
*/

return [
    'timezone' => env('WORKFORCE_TIMEZONE', env('MEETINGS_TIMEZONE', 'Africa/Bamako')),

    // Created for a team the first time it opens Planning.
    'shift_templates' => [
        ['code' => 'A', 'name' => 'Matin / journée', 'start_time' => '08:00', 'end_time' => '16:00', 'break_minutes' => 60, 'min_agents' => 0, 'color' => 'blue'],
        ['code' => 'B', 'name' => 'Soir / nuit', 'start_time' => '16:00', 'end_time' => '00:00', 'break_minutes' => 60, 'min_agents' => 0, 'color' => 'violet'],
    ],

    'rules' => [
        // Longest stretch of back-to-back shifts (A + B = 16 h).
        'max_consecutive_hours' => 16,
        // Rest between two stretches of work.
        'min_rest_hours' => 8,
        // Rest after a stretch longer than one shift (8 h), e.g. after a double A + B.
        'min_rest_after_long_hours' => 12,
        // Scheduled hours per week (Monday–Sunday); 0 = no cap.
        'max_weekly_hours' => 48,
        // block: refuse what breaks a rule; warn: allow it and flag it.
        'enforcement' => 'block',
        'late_tolerance_minutes' => 5,
    ],

    // Created for a team the first time it opens Planning. annual_days null = no balance.
    'leave_types' => [
        ['code' => 'pto', 'name' => 'Congés payés', 'paid' => true, 'annual_days' => 30],
        ['code' => 'sick', 'name' => 'Congé maladie', 'paid' => true, 'annual_days' => null],
        ['code' => 'unpaid', 'name' => 'Congé sans solde', 'paid' => false, 'annual_days' => null],
        ['code' => 'emergency', 'name' => 'Congé exceptionnel / urgence', 'paid' => true, 'annual_days' => 5],
    ],

    // Hours counted as one day of leave for partial-shift requests.
    'hours_per_day' => 8,
];
