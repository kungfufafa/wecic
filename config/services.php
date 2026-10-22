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
    ],

];
