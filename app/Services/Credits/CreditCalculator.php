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

    public function starterTokens(): int
    {
        return max(0, (int) config('credits.starter_tokens', 50));
    }

    /**
     * @param  'video'|'tool'|'music'|'voice'  $product
     */
    public function minCredits(string $product): int
    {
        return max(0, (int) config("credits.min_credits.{$product}", 0));
    }

    /**
     * Bump sub-floor charges up to the product minimum (when charge > 0).
     *
     * @param  'video'|'tool'|'music'|'voice'  $product
     */
    public function applyFloor(int $credits, string $product): int
    {
        $min = $this->minCredits($product);

        if ($credits > 0 && $min > 0 && $credits < $min) {
            return $min;
        }

        return $credits;
    }

    public function elevenlabsMultiplier(): int
    {
        return max(1, (int) config('credits.elevenlabs_multiplier', 5));
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

    /**
     * Shared with the Inertia frontend for live credit estimates.
     *
     * @return array{
     *   markup: float,
     *   usd_per_credit: float,
     *   starter_tokens: int,
     *   min_credits: array{video: int, tool: int, music: int, voice: int},
     *   elevenlabs_multiplier: int
     * }
     */
    public function frontendConfig(): array
    {
        return [
            'markup' => (float) config('credits.markup', 1.25),
            'usd_per_credit' => (float) config('credits.usd_per_credit', 0.01),
            'starter_tokens' => $this->starterTokens(),
            'min_credits' => [
                'video' => $this->minCredits('video'),
                'tool' => $this->minCredits('tool'),
                'music' => $this->minCredits('music'),
                'voice' => $this->minCredits('voice'),
            ],
            'elevenlabs_multiplier' => $this->elevenlabsMultiplier(),
        ];
    }
}
