<?php

return [

    // Shown on exported reports.
    'company_name' => env('COMPANY_NAME', env('APP_NAME', 'SMC Field Ops')),

    'auth' => [
        'access_token_ttl_minutes' => (int) env('AUTH_ACCESS_TOKEN_TTL_MINUTES', 1440),
        'refresh_token_ttl_days' => (int) env('AUTH_REFRESH_TOKEN_TTL_DAYS', 30),
        // Spec 6.1: max 5 attempts per 15 minutes per phone; lock after 5 consecutive failures.
        'max_login_attempts' => (int) env('AUTH_MAX_PIN_ATTEMPTS', 5),
        'login_attempt_window_minutes' => (int) env('AUTH_PIN_ATTEMPT_WINDOW_MINUTES', 15),
    ],

    'shifts' => [
        // Shift templates are wall-clock times in this zone; everything is stored in UTC.
        'timezone' => env('SHIFTS_TIMEZONE', 'Asia/Riyadh'),
        'early_check_in_minutes' => (int) env('SHIFTS_EARLY_CHECK_IN_MINUTES', 60),
        // A forgotten check-out is closed automatically this long after the scheduled end.
        'auto_check_out_after_minutes' => (int) env('SHIFTS_AUTO_CHECK_OUT_AFTER_MINUTES', 240),
        'max_assignment_days' => 62,
    ],

    'telemetry' => [
        'max_batch_size' => 500,
        // Buffered offline pings older than this are dropped.
        'max_ping_age_hours' => 24,
        // Live-map freshness, judged against the rhythm the phone should be on.
        // Moving phones ping every 4s (spec 4.4: green within 10s, amber 10-30s);
        // phones stationary for 2+ minutes ping every 15s, so allow for that.
        'freshness' => [
            'moving' => ['live' => 10, 'lagging' => 30],
            'stationary' => ['live' => 25, 'lagging' => 60],
        ],
        // At or below this speed (m/s) the last ping counts as stationary; same as the app.
        'moving_speed' => 1.0,
    ],

    'documents' => [
        'disk' => env('DOCUMENTS_DISK', 'local'),
        'max_size_kb' => 10240,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
    ],

];
