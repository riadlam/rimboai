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
];
