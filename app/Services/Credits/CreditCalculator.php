<?php

namespace App\Services\Credits;

class CreditCalculator
{
    /**
     * credits_to_charge = ceil( (fal_cost_usd * markup) / usd_per_credit )
     */
    public function fromFalUsd(float $falCostUsd): int
    {
        $markup = (float) config('credits.markup', 1.25);
        $usdPerCredit = (float) config('credits.usd_per_credit', 0.01);

        if ($falCostUsd <= 0 || $usdPerCredit <= 0) {
            return 0;
        }

        return (int) ceil(($falCostUsd * $markup) / $usdPerCredit);
    }

    /**
     * @return array{markup: float, usd_per_credit: float}
     */
    public function config(): array
    {
        return [
            'markup' => (float) config('credits.markup', 1.25),
            'usd_per_credit' => (float) config('credits.usd_per_credit', 0.01),
        ];
    }
}
