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
    | Stalled intake reminder (BL-015)
    |--------------------------------------------------------------------------
    |
    | After N days without completion, send at most one reminder with the
    | same customer resume link. Skipped for demos, revoked/expired tokens,
    | and when MAIL_MAILER=log (ADR-0002).
    |
    */

    'reminder' => [
        'days' => (int) env('INTAKE_REMINDER_DAYS', 3),
    ],

    'follow_up' => [
        'max_rounds' => (int) env('INTAKE_FOLLOW_UP_MAX_ROUNDS', 3),
        'max_items_per_round' => (int) env('INTAKE_FOLLOW_UP_MAX_ITEMS', 5),
        'max_photos_per_item' => (int) env('INTAKE_FOLLOW_UP_MAX_PHOTOS', 5),
        'max_documents_per_item' => (int) env('INTAKE_FOLLOW_UP_MAX_DOCUMENTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft-delete retention (BL-009)
    |--------------------------------------------------------------------------
    |
    | Days after soft delete before hard purge (DB cascade + media files).
    |
    */

    'retention' => [
        'soft_delete_days' => (int) env('INTAKE_SOFT_DELETE_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public demo ("Start demo" on homepage)
    |--------------------------------------------------------------------------
    |
    | Creates a temporary airco-intake + customer link without account signup.
    | On by default everywhere so any visitor can try the product. Set
    | DEMO_ENABLED=false only to hide the homepage button and block starts.
    |
    */

    'demo' => [
        'enabled' => filter_var(env('DEMO_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'ttl_hours' => (int) env('DEMO_TTL_HOURS', 12),
        'user_email' => env('DEMO_USER_EMAIL', 'demo@intake-engine.invalid'),
        'throttle_per_hour' => (int) env('DEMO_THROTTLE_PER_HOUR', 5),

        /*
        | Het demo-adres moet een BESTAAND BAG-adres zijn, anders levert PDOK niets
        | en vervalt er in de demo geen enkele vraag. Bewust een publiek pand — geen
        | woonadres van een particulier.
        */
        'address' => [
            'line' => env('DEMO_ADDRESS_LINE', 'Damrak 1'),
            'postal_code' => env('DEMO_ADDRESS_POSTAL_CODE', '1012LG'),
            'city' => env('DEMO_ADDRESS_CITY', 'Amsterdam'),
        ],
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
        'document_mimes' => ['application/pdf'],
    ],

];
