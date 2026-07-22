<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pdok' => [
        'enabled' => (bool) env('PDOK_ENABLED', true),
        'search_base_url' => env('PDOK_SEARCH_BASE_URL', 'https://api.pdok.nl/bzk/locatieserver/search/v3_1'),
        'bag_base_url' => env('PDOK_BAG_BASE_URL', 'https://api.pdok.nl/kadaster/bag/ogc/v2'),
        'timeout_seconds' => (int) env('PDOK_TIMEOUT_SECONDS', 5),
        'aerial_enabled' => (bool) env('PDOK_AERIAL_ENABLED', true),
        'aerial_wms_url' => env('PDOK_AERIAL_WMS_URL', 'https://service.pdok.nl/hwh/luchtfotorgb/wms/v1_0'),
        'aerial_layer' => env('PDOK_AERIAL_LAYER', 'Actueel_orthoHR'),
        'aerial_timeout_seconds' => (int) env('PDOK_AERIAL_TIMEOUT_SECONDS', 4),
        'aerial_width' => (int) env('PDOK_AERIAL_WIDTH', 900),
        'aerial_height' => (int) env('PDOK_AERIAL_HEIGHT', 600),
        'aerial_ground_width_meters' => (int) env('PDOK_AERIAL_GROUND_WIDTH_METERS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | 3DBAG — pandgeometrie (TU Delft)
    |--------------------------------------------------------------------------
    |
    | Open data onder CC BY 4.0: opslaan en tonen in het dossier mag, mits de bron
    | vermeld blijft. Levert dakvorm en gevelhoogte bij het BAG-pand-id dat de
    | PDOK-verrijking al heeft opgehaald. Soft-fail: een storing blokkeert niets.
    |
    */

    'threedbag' => [
        'enabled' => (bool) env('THREEDBAG_ENABLED', true),
        'base_url' => env('THREEDBAG_BASE_URL', 'https://api.3dbag.nl'),
        'timeout_seconds' => (int) env('THREEDBAG_TIMEOUT_SECONDS', 5),
    ],

];
