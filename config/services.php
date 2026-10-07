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

    'fal' => [
        'key' => env('FAL_KEY'),
        // Platform/Admin API key for billing + usage (env key is literally admin_key).
        'admin_key' => env('admin_key'),
        // Optional override; defaults to {APP_URL}/webhooks/fal when APP_URL is https.
        'webhook_url' => env('FAL_WEBHOOK_URL'),
    ],

    'higgsfield' => [
        // Full "KEY_ID:KEY_SECRET" string for Authorization: Key …
        'key' => env('HIGGSFIELD_KEY'),
        // Docs use both hosts; platform returns status_url/cancel_url for jobs.
        'base_url' => env('HIGGSFIELD_BASE_URL', 'https://platform.higgsfield.ai'),
        // Optional override; defaults to {APP_URL}/webhooks/higgsfield when APP_URL is https.
        'webhook_url' => env('HIGGSFIELD_WEBHOOK_URL'),
        // Genjutsu Motion Transfer list rates (USD per ceil input-video second).
        'genjutsu_motion_transfer' => [
            '480p' => (float) env('HIGGSFIELD_GENJUTSU_USD_480P', 0.318),
            '720p' => (float) env('HIGGSFIELD_GENJUTSU_USD_720P', 0.681),
            '1080p' => (float) env('HIGGSFIELD_GENJUTSU_USD_1080P', 1.632),
        ],
    ],

    /** Optional absolute path to ffmpeg for "Continue from last frame". */
    'ffmpeg_path' => env('FFMPEG_PATH'),

    'telegram' => [
        // Bot 1 — model pricing sync reports
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        // Bot 2 — user creations + token purchase alerts
        'creations_bot_token' => env('TELEGRAM_CREATIONS_BOT_TOKEN'),
        'creations_chat_id' => env('TELEGRAM_CREATIONS_CHAT_ID'),
        // Secret for X-Telegram-Bot-Api-Secret-Token on /webhooks/telegram/creations
        'creations_webhook_secret' => env('TELEGRAM_CREATIONS_WEBHOOK_SECRET'),
    ],

    'sofizpay' => [
        'enabled' => env('SOFIZPAY_ENABLED', true),
        'sandbox' => env('SOFIZPAY_SANDBOX', false),
        'base_url' => env('SOFIZPAY_BASE_URL', 'https://sofizpay.com'),
        'merchant_account' => env('SOFIZPAY_MERCHANT_ACCOUNT'),
        'timeout' => (int) env('SOFIZPAY_TIMEOUT', 30),
        // Use "no" for server-side create: SofizPay returns JSON with payment_url.
        // "yes" can 302 to SATIM HTML and breaks Laravel Http::get().
        'redirect' => env('SOFIZPAY_REDIRECT', 'no'),
        'keep_return_url' => env('SOFIZPAY_KEEP_RETURN_URL', 'True'),
        // Minimum accepted amount in DZD (SATIM/CIB rejects tiny amounts).
        'min_amount_dzd' => (float) env('SOFIZPAY_MIN_AMOUNT_DZD', 75),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'gtm' => [
        'id' => env('GTM_ID', 'GTM-PWZGLWKN'),
    ],

];
