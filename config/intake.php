<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Customer access token lifetime
    |--------------------------------------------------------------------------
    */

    'token_ttl_days' => (int) env('INTAKE_TOKEN_TTL_DAYS', 60),

    /*
    |--------------------------------------------------------------------------
    | Public demo ("Start demo" on homepage)
    |--------------------------------------------------------------------------
    |
    | Creates a temporary airco-intake + customer link without account signup.
    | Default on for local/staging; keep off in production until intentional.
    |
    */

    'demo' => [
        'enabled' => (bool) env('DEMO_ENABLED', false),
        'ttl_hours' => (int) env('DEMO_TTL_HOURS', 12),
        'user_email' => env('DEMO_USER_EMAIL', 'demo@intake-engine.invalid'),
        'throttle_per_hour' => (int) env('DEMO_THROTTLE_PER_HOUR', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Photo uploads
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        'max_kilobytes' => (int) env('INTAKE_UPLOAD_MAX_KB', 5120),
        'max_files_per_question' => (int) env('INTAKE_UPLOAD_MAX_FILES', 5),
        'conversion' => [
            'heic_to_jpeg_quality' => 82,
            'max_long_edge' => 3000,
        ],
        'accepted_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/heic',
            'image/heif',
            'image/heic-sequence',
            'image/heif-sequence',
        ],
        'accepted_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'],
        'stored_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'stored_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

];
