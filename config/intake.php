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
    | Public interactive installer demo
    |--------------------------------------------------------------------------
    |
    | Creates a temporary, isolated installer workspace without account signup.
    | The scenario uses fictitious precomputed data and never sends external
    | messages or invokes live AI. Set DEMO_ENABLED=false to block new starts.
    |
    */

    'demo' => [
        'enabled' => filter_var(env('DEMO_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'ttl_hours' => (int) env('DEMO_TTL_HOURS', 2),
        'user_email' => env('DEMO_USER_EMAIL', 'demo@intake-engine.invalid'),
        'installer_password' => env('DEMO_INSTALLER_PASSWORD'),
        'throttle_per_hour' => (int) env('DEMO_THROTTLE_PER_HOUR', 5),

        /*
        | Uitsluitend fictieve presentatiewaarden. De publieke demo benadert geen
        | adresbron; BAG, luchtfoto, EP-Online en 3DBAG zijn vooraf berekend.
        */
        'address' => [
            'line' => env('DEMO_ADDRESS_LINE', 'Voorbeeldstraat 12'),
            'postal_code' => env('DEMO_ADDRESS_POSTAL_CODE', '1234AB'),
            'house_number' => (int) env('DEMO_ADDRESS_HOUSE_NUMBER', 12),
            'house_number_addition' => env('DEMO_ADDRESS_HOUSE_NUMBER_ADDITION'),
            'city' => env('DEMO_ADDRESS_CITY', 'Voorbeeldstad'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public product interest form
    |--------------------------------------------------------------------------
    |
    | Submissions are retained in the application even when mail is unavailable.
    | A configured recipient receives a queued notification, except through the
    | log mailer so contact details never end up in application logs.
    |
    */

    'interest' => [
        'recipient' => env('PRODUCT_INTEREST_MAIL_TO'),
        'throttle_per_hour' => (int) env('PRODUCT_INTEREST_THROTTLE_PER_HOUR', 5),
        'retention_days' => (int) env('PRODUCT_INTEREST_RETENTION_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Photo uploads
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        'max_kilobytes' => (int) env('INTAKE_UPLOAD_MAX_KB', 5120),
        'max_files_per_question' => (int) env('INTAKE_UPLOAD_MAX_FILES', 5),
        'dossier' => [
            'max_long_edge' => (int) env('INTAKE_DOSSIER_MAX_LONG_EDGE', 2048),
            'jpeg_quality' => (int) env('INTAKE_DOSSIER_JPEG_QUALITY', 82),
        ],
        'analysis' => [
            'max_long_edge' => (int) env('INTAKE_ANALYSIS_MAX_LONG_EDGE', 1536),
            'jpeg_quality' => (int) env('INTAKE_ANALYSIS_JPEG_QUALITY', 80),
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
        'stored_mimes' => ['image/jpeg'],
        'stored_extensions' => ['jpg', 'jpeg'],
        'document_mimes' => ['application/pdf'],
    ],

];
