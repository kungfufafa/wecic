<?php

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    // Google Gemini (Generative Language API), used by the Notebook.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL') ?: 'gemini-2.5-flash',
        'fallback_model' => env('GEMINI_FALLBACK_MODEL') ?: null,
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) (env('GEMINI_TIMEOUT') ?: 60),
        'retry_delay_ms' => (int) env('GEMINI_RETRY_DELAY_MS', 1200),
        // Seconds one web request may spend on all Gemini attempts together;
        // keep it under the web server's own timeout (nginx: 60 s).
        'request_budget' => (int) (env('GEMINI_REQUEST_BUDGET') ?: 50),
        // Reading a PDF or image runs in a queued job, which may wait longer.
        'ingest_budget' => (int) (env('GEMINI_INGEST_BUDGET') ?: 240),
        // Whether the key is on the Gemini API free tier, under which Google
        // may keep what is sent and use it to improve its products. Students
        // are told so before they consent; false (a paid key) drops that copy.
        'free_tier' => (bool) env('GEMINI_FREE_TIER', true),
    ],

    // Anthropic Claude (Messages API), the Notebook's model when
    // NOTEBOOK_AI_PROVIDER=anthropic. Model ids are aliases without a date
    // suffix: claude-opus-5-5 (default), claude-sonnet-5-5, claude-haiku-5-5.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL') ?: 'claude-opus-5-5',
        'fallback_model' => env('ANTHROPIC_FALLBACK_MODEL') ?: null,
        'base_url' => env('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com',
        // The `anthropic-version` header; 2026-10-21 takes no temperature,
        // top_p or top_k.
        'version' => env('ANTHROPIC_VERSION') ?: '2026-10-21',
        // output_config.effort (low, medium, high, xhigh, max); empty
        // leaves the model's default (medium on Claude Opus 5.5).
        'effort' => env('ANTHROPIC_EFFORT') ?: null,
        // The largest output limit sent: 128000 on Claude Opus, Sonnet and
        // Haiku 5.5; lower it for an older model with a smaller ceiling.
        'max_output_tokens' => (int) (env('ANTHROPIC_MAX_OUTPUT_TOKENS') ?: 128000),
        // Tokens added to every output limit for adaptive thinking, which
        // counts against max_tokens (empty: 8192).
        'thinking_headroom' => env('ANTHROPIC_THINKING_HEADROOM') ?: null,
        'timeout' => (int) (env('ANTHROPIC_TIMEOUT') ?: 60),
        'retry_delay_ms' => (int) env('ANTHROPIC_RETRY_DELAY_MS', 1200),
    ],

    // Any OpenAI-compatible Chat Completions endpoint (OpenAI, a gateway,
    // or a local server such as Ollama or vLLM), the Notebook's model when
    // NOTEBOOK_AI_PROVIDER=openai. The key may stay empty for a server on
    // localhost or a *.test host. What the model can read and how it
    // answers in JSON are set here, since the endpoint does not say.
    'openai_compatible' => [
        'key' => env('OPENAI_COMPATIBLE_API_KEY'),
        'base_url' => env('OPENAI_COMPATIBLE_BASE_URL') ?: 'https://api.openai.com/v1',
        'model' => env('OPENAI_COMPATIBLE_MODEL') ?: null,
        'fallback_model' => env('OPENAI_COMPATIBLE_FALLBACK_MODEL') ?: null,
        // The provider's name as students read it.
        'label' => env('OPENAI_COMPATIBLE_LABEL') ?: 'OpenAI',
        'image_input' => (bool) env('OPENAI_COMPATIBLE_IMAGE_INPUT', false),
        'pdf_input' => (bool) env('OPENAI_COMPATIBLE_PDF_INPUT', false),
        // json_schema (strict schema), json_object (JSON, schema in the
        // prompt) or none (JSON read from plain text).
        'structured_output' => env('OPENAI_COMPATIBLE_STRUCTURED_OUTPUT') ?: 'json_schema',
        // max_tokens, or max_completion_tokens for OpenAI's newer models.
        'token_param' => env('OPENAI_COMPATIBLE_TOKEN_PARAM') ?: 'max_completion_tokens',
        // The largest output limit the model takes.
        'max_output_tokens' => (int) (env('OPENAI_COMPATIBLE_MAX_OUTPUT_TOKENS') ?: 16384),
        // Whether to send temperature; some models take only the default.
        'send_temperature' => (bool) env('OPENAI_COMPATIBLE_SEND_TEMPERATURE', true),
        'timeout' => (int) (env('OPENAI_COMPATIBLE_TIMEOUT') ?: 60),
        'retry_delay_ms' => (int) env('OPENAI_COMPATIBLE_RETRY_DELAY_MS', 1200),
        // Whether the endpoint is a free tier that may keep what is sent and
        // use it to improve its products; students are told so when true.
        'free_tier' => (bool) env('OPENAI_COMPATIBLE_FREE_TIER', false),
    ],

    // Expo push service, for notifications to the mobile app
    // (PortalPushNotifier). The access token is only needed when push
    // security is switched on for the Expo project.
    'expo' => [
        'push_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
        'access_token' => env('EXPO_ACCESS_TOKEN'),
        'timeout' => (int) (env('EXPO_PUSH_TIMEOUT') ?: 15),
    ],

];
