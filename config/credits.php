<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Credit peg & markup
    |--------------------------------------------------------------------------
    |
    | 1 credit = $0.01 USD face value.
    | Client price = fal cost × markup, converted to credits and rounded up:
    |   credits = ceil( (fal_cost_usd * markup) / usd_per_credit )
    |
    */
    'usd_per_credit' => (float) env('CREDITS_USD_PER_CREDIT', 0.01),
    'markup' => (float) env('CREDITS_MARKUP', 1.25),

    /*
    |--------------------------------------------------------------------------
    | Production economics and pricing safety
    |--------------------------------------------------------------------------
    */
    'usd_dzd_rate' => (float) env('USD_DZD_RATE', 250),
    'gateway_fee_percent' => (float) env('PAYMENT_GATEWAY_FEE_PERCENT', 0),
    'gateway_fee_fixed_dzd' => (float) env('PAYMENT_GATEWAY_FEE_FIXED_DZD', 0),
    'pricing_max_age_minutes' => (int) env('FAL_PRICING_MAX_AGE_MINUTES', 1440),
    'pricing_min_coverage' => (float) env('FAL_PRICING_MIN_COVERAGE', 1.0),
    'pricing_max_change_ratio' => (float) env('FAL_PRICING_MAX_CHANGE_RATIO', 4.0),
    'estimate_variance_alert_percent' => (float) env('FAL_ESTIMATE_VARIANCE_ALERT_PERCENT', 15),

    /*
    |--------------------------------------------------------------------------
    | Starter balance & optional minimum charges
    |--------------------------------------------------------------------------
    |
    | starter_tokens: granted on email/Google signup (also users.tokens DB default).
    | min_credits.*: optional floor after markup (0 = disabled). Charge = ceil(Fal × markup).
    |
    */
    'starter_tokens' => (int) env('CREDITS_STARTER_TOKENS', 25),

    'min_credits' => [
        'video' => (int) env('CREDITS_MIN_VIDEO', 0),
        'tool' => (int) env('CREDITS_MIN_TOOL', 0),
        'music' => (int) env('CREDITS_MIN_MUSIC', 0),
        'voice' => (int) env('CREDITS_MIN_VOICE', 0),
    ],

    /** ElevenLabs TTS multiplier applied after markup (voice lab only). */
    'elevenlabs_multiplier' => (int) env('CREDITS_ELEVENLABS_MULTIPLIER', 5),
];
