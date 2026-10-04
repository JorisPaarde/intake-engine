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
    | Address enrichment and AI (photo/text/synthesis) follow the same path as
    | production when those integrations are enabled. Mail and PDF stay off.
    | Set DEMO_ENABLED=false to block new starts.
    |
    */

    'demo' => [
        'enabled' => filter_var(env('DEMO_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'ttl_hours' => (int) env('DEMO_TTL_HOURS', 2),
        'user_email' => env('DEMO_USER_EMAIL', 'demo@intake-engine.invalid'),
        'installer_password' => env('DEMO_INSTALLER_PASSWORD'),
        'throttle_per_hour' => (int) env('DEMO_THROTTLE_PER_HOUR', 5),

        /*
        | Tipadres op het create-formulier (niet vooringevuld). De installateur typt
        | postcode/huisnummer zelf; dit voorbeeld is een bekende PDOK/BAG-match.
        | Geen echte persoonsgegevens — alleen een openbaar adresvoorbeeld.
        */
        'address' => [
            'line' => env('DEMO_ADDRESS_LINE', 'Bernadottelaan 273'),
            'postal_code' => env('DEMO_ADDRESS_POSTAL_CODE', '2037GR'),
            'house_number' => (int) env('DEMO_ADDRESS_HOUSE_NUMBER', 273),
            'house_number_addition' => env('DEMO_ADDRESS_HOUSE_NUMBER_ADDITION'),
            'city' => env('DEMO_ADDRESS_CITY', 'Haarlem'),
        ],

        // Tiptekst / placeholder op create — niet vooringevuld in het formulier.
        'customer_name' => env('DEMO_CUSTOMER_NAME', 'Familie de Vries'),
        'customer_email_domain' => '@demo.invalid',
        'request_reason' => env(
            'DEMO_REQUEST_REASON',
            'Twee slaapkamers op zolder koelen; het wordt daar te warm in de zomer.',
        ),
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
        // Default lead inbox for homepage interest + demo PDF requests (BL-043/BL-051).
        'recipient' => env('PRODUCT_INTEREST_MAIL_TO', 'info@jpwebcreation.nl'),
        'throttle_per_hour' => (int) env('PRODUCT_INTEREST_THROTTLE_PER_HOUR', 5),
        'retention_days' => (int) env('PRODUCT_INTEREST_RETENTION_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Photo uploads
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        'max_kilobytes' => (int) env('INTAKE_UPLOAD_MAX_KB', 8192),
        'max_files_per_question' => (int) env('INTAKE_UPLOAD_MAX_FILES', 5),
        'dossier' => [
            'max_long_edge' => (int) env('INTAKE_DOSSIER_MAX_LONG_EDGE', 2048),
            'jpeg_quality' => (int) env('INTAKE_DOSSIER_JPEG_QUALITY', 82),
        ],
        'analysis' => [
            'max_long_edge' => (int) env('INTAKE_ANALYSIS_MAX_LONG_EDGE', 1536),
            'jpeg_quality' => (int) env('INTAKE_ANALYSIS_JPEG_QUALITY', 80),
        ],
        // Imagick pixel-cache caps (bytes). Outside PHP memory_get_peak_usage; keeps RSS under host PMEM.
        'imagick_memory_bytes' => (int) env('INTAKE_IMAGICK_MEMORY_BYTES', 128 * 1024 * 1024),
        'imagick_map_bytes' => (int) env('INTAKE_IMAGICK_MAP_BYTES', 192 * 1024 * 1024),
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

    /*
    |--------------------------------------------------------------------------
    | PHP memory (web vs CLI)
    |--------------------------------------------------------------------------
    |
    | Web (LSAPI): public/.user.ini memory_limit=256M (BL-141). CLI ignores .user.ini.
    | Hosting CLI is already 256M; AppServiceProvider only caps unlimited (-1) or
    | raises a too-low default (vangnet). Queue --memory is Laravel's worker restart
    | threshold in MB (not PHP memory_limit); keep aligned with CLI at 256.
    |
    */

    'php' => [
        'cli_memory_limit' => (string) env('PHP_CLI_MEMORY_LIMIT', '256M'),
        'queue_worker_memory_mb' => (int) env('QUEUE_WORKER_MEMORY_MB', 256),
    ],

];
