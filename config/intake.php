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
    | Photo uploads
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        'max_kilobytes' => (int) env('INTAKE_UPLOAD_MAX_KB', 5120),
        'max_files_per_question' => (int) env('INTAKE_UPLOAD_MAX_FILES', 5),
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

];
