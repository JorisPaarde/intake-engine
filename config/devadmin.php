<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Dev-admin (staging-inzage)
    |--------------------------------------------------------------------------
    |
    | De dev-admin onder /dev toont ruwe intake-data (inclusief PII) en de status
    | van externe diensten, zodat op staging te controleren is of de APIs werken
    | en welke data er bij een opname binnenkwam. Hij staat daarom standaard alleen
    | aan op 'local' en 'staging' en is in 'production' automatisch uit — dáár is
    | geen env-var voor nodig. Zet DEV_ADMIN_ENABLED=false om hem ook op staging
    | tijdelijk uit te schakelen. Zie docs/decisions/0008-dev-admin-staging-only.md.
    |
    */

    'enabled' => (bool) env(
        'DEV_ADMIN_ENABLED',
        in_array(env('APP_ENV', 'production'), ['local', 'staging'], true),
    ),

    /*
    |--------------------------------------------------------------------------
    | AI-invoer testen (BL-104)
    |--------------------------------------------------------------------------
    |
    | Dry-run van openingszin → lokale parser → catalogus-AI. Begrens lengte en
    | tempo zodat staging-testers geen kostbare herhaalcalls of logspam veroorzaken.
    |
    */
    'ai_input_test' => [
        'max_length' => (int) env('DEV_AI_INPUT_TEST_MAX_LENGTH', 2000),
        'throttle_per_minute' => (int) env('DEV_AI_INPUT_TEST_THROTTLE_PER_MINUTE', 10),
    ],

];
