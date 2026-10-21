<?php

return [

    'auth' => [
        'access_token_ttl_minutes' => (int) env('AUTH_ACCESS_TOKEN_TTL_MINUTES', 1440),
        'refresh_token_ttl_days' => (int) env('AUTH_REFRESH_TOKEN_TTL_DAYS', 30),
        // Spec 6.1: max 5 attempts per 15 minutes per phone; lock after 5 consecutive failures.
        'max_login_attempts' => (int) env('AUTH_MAX_PIN_ATTEMPTS', 5),
        'login_attempt_window_minutes' => (int) env('AUTH_PIN_ATTEMPT_WINDOW_MINUTES', 15),
    ],

    'documents' => [
        'disk' => env('DOCUMENTS_DISK', 'local'),
        'max_size_kb' => 10240,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
    ],

];
