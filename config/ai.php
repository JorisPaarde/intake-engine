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

    /*
    |--------------------------------------------------------------------------
    | Begeleide leidingroute (guided pipe route)
    |--------------------------------------------------------------------------
    |
    | Aparte, zwaardere fotoanalyse die per foto beoordeelt of de wand/doorvoer
    | zichtbaar is en of een route naar buiten aannemelijk is, en die de segmenten
    | tot één leidingroute samenvat. Bewust een eigen modelkeuze, los van het
    | globale `ai.model`: dit draait alleen op deze complexe route-analyse.
    |
    | `model` doet de standaardanalyse; bij lage zekerheid of een complexe route
    | escaleert de synthese naar het capabelere `review_model`. De installateur
    | keurt de uiteindelijke route altijd goed (zie ADR-0008-... / docs/ai.md).
    |
    | Model-ID's zijn overschrijfbaar via .env, zodat een nieuwe generatie zonder
    | codewijziging in te zetten is. Vereist AI_PROVIDER=openai + key + DPIA.
    |
    */

    'route' => [
        'enabled' => (bool) env('AI_ROUTE_ANALYSIS_ENABLED', false),
        'model' => env('AI_ROUTE_MODEL', 'gpt-5.6-terra'),
        'review_model' => env('AI_ROUTE_REVIEW_MODEL', 'gpt-5.6-sol'),
        'escalate_below_confidence' => (float) env('AI_ROUTE_ESCALATE_BELOW', 0.7),
        'max_images' => (int) env('AI_ROUTE_MAX_IMAGES', 4),
        'analysis_prompt' => 'route_photo_analysis',
        'synthesis_prompt' => 'route_synthesis',
    ],

];
