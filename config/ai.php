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

    /*
    |--------------------------------------------------------------------------
    | External AI budget guard
    |--------------------------------------------------------------------------
    |
    | Applies only to paid external provider calls (`AI_PROVIDER=openai`). Enforcement
    | is fail-closed by default: if OpenAI is active but no daily/monthly cap is set,
    | the provider call soft-fails before spending. Costs are estimated from returned
    | token usage plus optional image/run reservations; keep rates conservative.
    |
    */

    'budget' => [
        'enforced' => filter_var(env('AI_BUDGET_ENFORCED', true), FILTER_VALIDATE_BOOLEAN),
        'daily_cents' => env('AI_BUDGET_DAILY_CENTS'),
        'monthly_cents' => env('AI_BUDGET_MONTHLY_CENTS'),
        'reserve_cents_per_call' => (int) env('AI_BUDGET_RESERVE_CENTS_PER_CALL', 1),
        'input_cents_per_1k_tokens' => (float) env('AI_BUDGET_INPUT_CENTS_PER_1K_TOKENS', 0),
        'output_cents_per_1k_tokens' => (float) env('AI_BUDGET_OUTPUT_CENTS_PER_1K_TOKENS', 0),
        'image_cents_per_image' => (float) env('AI_BUDGET_IMAGE_CENTS_PER_IMAGE', 0),
    ],

    'photo_inference' => [
        'enabled' => (bool) env('AI_PHOTO_INFERENCE_ENABLED', false),
        'max_images' => (int) env('AI_PHOTO_INFERENCE_MAX_IMAGES', 2),
        'observation_min_confidence' => (float) env('AI_PHOTO_OBSERVATION_MIN_CONFIDENCE', 0.65),
    ],

    'text_inference' => [
        'enabled' => (bool) env('AI_TEXT_INFERENCE_ENABLED', false),
    ],

    'summary_prompt' => 'summary',

    'attention_points_prompt' => 'attention_points',

    'fusebox_prompt' => 'fusebox_assessment',

    'installer_photo_observation_prompt' => 'installer_photo_observation',

    'request_intent_prompt' => 'request_intent',

    'request_prefill_prompt' => 'request_prefill',

    'dossier' => [
        'enabled' => (bool) env('AI_DOSSIER_SYNTHESIS_ENABLED', false),
        'model' => env('AI_DOSSIER_MODEL', 'gpt-5.6-terra'),
        'max_images' => (int) env('AI_DOSSIER_MAX_IMAGES', 12),
        'prompt' => 'dossier_synthesis',
    ],

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
