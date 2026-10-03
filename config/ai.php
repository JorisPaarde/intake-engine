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
    | openai     — externe OpenAI-compatibele provider (vereist AI_API_KEY + budgetcaps)
    |
    | LET OP: 'openai' stuurt (geredigeerde) inhoud naar een externe partij.
    | Vereist AI_API_KEY en minstens één budgetcap in .env. Standaard blijft 'null'.
    |
    */

    'provider' => env('AI_PROVIDER', 'null'),

    'api_key' => env('AI_API_KEY'),

    // OpenAI-compatible base URL. OpenRouter: https://openrouter.ai/api/v1
    'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),

    // Default model for text-only calls (samenvatting, prefill, aandachtspunten, …).
    'model' => env('AI_MODEL', 'gpt-4o-mini'),

    // Optional override when the request includes images and no per-call model is set.
    // Empty = fall back to AI_MODEL (same multimodal model for text and vision).
    'vision_model' => env('AI_VISION_MODEL'),

    // Optional OpenRouter attribution headers (HTTP-Referer / X-Title). Empty = omit.
    'http_referer' => env('AI_HTTP_REFERER'),
    'app_title' => env('AI_APP_TITLE'),

    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 20),

    /*
    |--------------------------------------------------------------------------
    | Temperatuur
    |--------------------------------------------------------------------------
    |
    | Standaardtekst-/synthesecalls: 0.2. Classificatie (foto-categorie, meterkast
    | free_group/fase, glas/stopcontact, follow-up subject) gebruikt 0 voor
    | deterministischer gedrag. Callers zetten dit via AiCompletionRequest;
    | OpenAiClient leest $request->temperature wanneer gezet.
    |
    */

    'temperature' => (float) env('AI_TEMPERATURE', 0.2),

    'classification_temperature' => (float) env('AI_CLASSIFICATION_TEMPERATURE', 0),

    /*
    |--------------------------------------------------------------------------
    | Max tokens (optional)
    |--------------------------------------------------------------------------
    |
    | When set (>0), OpenAiClient sends max_tokens on chat/completions and logs
    | it in ai_traces.model_parameters. Empty/null = provider default.
    |
    */

    'max_tokens' => env('AI_MAX_TOKENS'),

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

    /*
    |--------------------------------------------------------------------------
    | Foto-assessment queue (BL-121 + terminal status + BL-134 watchdog safety)
    |--------------------------------------------------------------------------
    |
    | ui_soft_timeout_seconds: na zoveel seconden stopt de wizard-poll met wachten
    | (vriendelijke melding; assessment blijft pending voor de watchdog).
    | watchdog_after_seconds: pending langer dan dit → herdispatch.
    | watchdog_max_attempts: daarna soft-fail not_assessed.
    | watchdog_max_age_hours: uploads ouder dan dit venster worden niet herqueued
    | (legacy/backfill-bescherming).
    | watchdog_max_per_run: harde cap per scheduler-tick.
    |
    */

    'photo_assessment' => [
        'ui_soft_timeout_seconds' => (int) env('AI_PHOTO_UI_SOFT_TIMEOUT_SECONDS', 90),
        'watchdog_after_seconds' => (int) env('AI_PHOTO_WATCHDOG_AFTER_SECONDS', 180),
        'watchdog_max_attempts' => (int) env('AI_PHOTO_WATCHDOG_MAX_ATTEMPTS', 3),
        'watchdog_max_age_hours' => (int) env('AI_PHOTO_WATCHDOG_MAX_AGE_HOURS', 24),
        'watchdog_max_per_run' => (int) env('AI_PHOTO_WATCHDOG_MAX_PER_RUN', 20),
    ],

    'text_inference' => [
        'enabled' => (bool) env('AI_TEXT_INFERENCE_ENABLED', false),
    ],

    'summary_prompt' => 'summary',

    'attention_points_prompt' => 'attention_points',

    'fusebox_prompt' => 'fusebox_assessment',

    'installer_photo_observation_prompt' => 'installer_photo_observation',

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
    | codewijziging in te zetten is. Vereist AI_PROVIDER=openai + key + budgetcaps
    | en AI_ROUTE_ANALYSIS_ENABLED=true.
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

    /*
    |--------------------------------------------------------------------------
    | AI-trace logging (klanttest 2026-10-02)
    |--------------------------------------------------------------------------
    |
    | Volledige keten logging: request → response → parse → field outcomes →
    | dossier/restvragen. Geen API-keys, klanttokens of base64 in de standaardlog.
    | Dev-admin `/dev/ai-traces` + CLI `ai:traces` / `ai:purge-traces`.
    |
    */

    'tracing' => [
        'enabled' => filter_var(env('AI_TRACING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'retention_days' => (int) env('AI_TRACE_RETENTION_DAYS', 30),
        'export_max_part_bytes' => (int) env('AI_TRACE_EXPORT_MAX_PART_BYTES', 1048576),
        'export_max_part_chars' => (int) env('AI_TRACE_EXPORT_MAX_PART_CHARS', 800000),
    ],

];
