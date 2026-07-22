<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | AI provider
    |--------------------------------------------------------------------------
    |
    | null       — AI uitgeschakeld (soft-fail)
    | fake       — vaste testdata
    | heuristic  — lokale deterministische samenvatting zonder externe API
    | openai     — externe OpenAI-compatibele provider (vereist AI_API_KEY + DPIA)
    |
    | LET OP: 'openai' stuurt (geredigeerde) inhoud naar een externe partij. Pas
    | activeren na DPIA/akkoord en met een key in .env. Standaard blijft 'null'.
    |
    */

    'provider' => env('AI_PROVIDER', 'null'),

    'api_key' => env('AI_API_KEY'),

    'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),

    'model' => env('AI_MODEL', 'gpt-4o-mini'),

    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 20),

    'photo_inference' => [
        'enabled' => (bool) env('AI_PHOTO_INFERENCE_ENABLED', false),
        'max_images' => (int) env('AI_PHOTO_INFERENCE_MAX_IMAGES', 2),
    ],

    'text_inference' => [
        'enabled' => (bool) env('AI_TEXT_INFERENCE_ENABLED', false),
    ],

    'summary_prompt' => 'summary',

    'attention_points_prompt' => 'attention_points',

    'fusebox_prompt' => 'fusebox_assessment',

    'request_intent_prompt' => 'request_intent',

];
