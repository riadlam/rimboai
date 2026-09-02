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
    | Starter balance & optional minimum charges
    |--------------------------------------------------------------------------
    |
    | starter_tokens: granted on email/Google signup (also users.tokens DB default).
    | min_credits.*: optional floor after markup (0 = disabled). Charge = ceil(Fal × markup).
    |
    */
    'starter_tokens' => (int) env('CREDITS_STARTER_TOKENS', 50),

    'min_credits' => [
        'video' => (int) env('CREDITS_MIN_VIDEO', 0),
        'tool' => (int) env('CREDITS_MIN_TOOL', 0),
        'music' => (int) env('CREDITS_MIN_MUSIC', 0),
        'voice' => (int) env('CREDITS_MIN_VOICE', 0),
    ],

    /** ElevenLabs TTS multiplier applied after markup (voice lab only). */
    'elevenlabs_multiplier' => (int) env('CREDITS_ELEVENLABS_MULTIPLIER', 5),
];
