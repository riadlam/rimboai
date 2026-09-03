<?php

namespace App\Services;

use App\Models\TokenLot;
use App\Models\TokenLotAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nominal token-face P&L plus FIFO cash basis when token lots exist.
 */
class CreationProfitCalculator
{
    /**
     * @return array{
     *   surface: string,
     *   tokens_charged: int,
     *   refunded: bool,
     *   net_tokens: int,
     *   estimated_fal_usd: ?float,
     *   actual_fal_usd: ?float,
     *   estimate_delta_usd: ?float,
     *   estimate_variance_percent: ?float,
     *   nominal_revenue_usd: float,
     *   nominal_profit_usd: ?float,
     *   nominal_margin_percent: ?float,
     *   cash_available: bool,
     *   cash_revenue_dzd: ?float,
     *   cash_revenue_usd: ?float,
     *   cash_profit_usd: ?float,
     *   cash_margin_percent: ?float,
     *   cash_note: ?string,
     *   negative_nominal: bool,
     *   variance_alert: bool
     * }
     */
    public function compute(Model $creation, string $creationType): array
    {
        $settings = is_array($creation->getAttribute('settings')) ? $creation->getAttribute('settings') : [];
        $tokens = (int) ($creation->getAttribute('credits_charged') ?? ($settings['credits'] ?? 0));
        $usdPerCredit = (float) config('credits.usd_per_credit', 0.01);
        $estimated = isset($settings['fal_cost_usd']) && is_numeric($settings['fal_cost_usd'])
            ? (float) $settings['fal_cost_usd']
            : null;
        $actual = $creation->getAttribute('cost_usd');
        $actual = $actual !== null && is_numeric($actual) ? (float) $actual : null;

        $refunded = $this->wasRefunded($creationType, (int) $creation->getKey());
        $netTokens = $refunded ? 0 : $tokens;
        $nominalRevenue = round($netTokens * $usdPerCredit, 6);
        $nominalProfit = $actual !== null ? round($nominalRevenue - $actual, 6) : null;
        $nominalMargin = $nominalRevenue > 0 && $nominalProfit !== null
            ? round(($nominalProfit / $nominalRevenue) * 100, 1)
            : null;

        $delta = $estimated !== null && $actual !== null ? round($actual - $estimated, 6) : null;
        $variancePct = $estimated !== null && $estimated > 0 && $actual !== null
            ? round((($actual / $estimated) - 1) * 100, 1)
            : null;
        $alertThreshold = (float) config('credits.estimate_variance_alert_percent', 15);

        $cash = $this->cashBasis($creation, $creationType, $netTokens);

        return [
            'surface' => $this->surface($creation, $creationType),
            'tokens_charged' => $tokens,
            'refunded' => $refunded,
            'net_tokens' => $netTokens,
            'estimated_fal_usd' => $estimated,
            'actual_fal_usd' => $actual,
            'estimate_delta_usd' => $delta,
            'estimate_variance_percent' => $variancePct,
            'nominal_revenue_usd' => $nominalRevenue,
            'nominal_profit_usd' => $nominalProfit,
            'nominal_margin_percent' => $nominalMargin,
            'cash_available' => $cash['available'],
            'cash_revenue_dzd' => $cash['revenue_dzd'],
            'cash_revenue_usd' => $cash['revenue_usd'],
            'cash_profit_usd' => $cash['profit_usd'],
            'cash_margin_percent' => $cash['margin_percent'],
            'cash_note' => $cash['note'],
            'negative_nominal' => $nominalProfit !== null && $nominalProfit < 0,
            'variance_alert' => $variancePct !== null && abs($variancePct) >= $alertThreshold,
        ];
    }

    public function surface(Model $creation, string $creationType): string
    {
        $mode = (string) ($creation->getAttribute('mode') ?? '');
        $settings = is_array($creation->getAttribute('settings')) ? $creation->getAttribute('settings') : [];

        if (! empty($settings['is_tool']) || str_starts_with($mode, 'tool:')) {
            $slug = str_starts_with($mode, 'tool:') ? substr($mode, 5) : (string) ($settings['tool_slug'] ?? 'tool');

            return 'tool:'.$slug;
        }

        if (! empty($settings['from_trend_id']) || $creation->getAttribute('trend_cost') !== null) {
            return 'trend_'.$creationType;
        }

        return 'lab_'.$creationType;
    }

    private function wasRefunded(string $creationType, int $creationId): bool
    {
        if (! Schema::hasTable('token_transactions')) {
            return false;
        }

        return DB::table('token_transactions')
            ->where('creation_type', $creationType)
            ->where('creation_id', $creationId)
            ->where('kind', 'refund')
            ->exists();
    }

    /**
     * @return array{available: bool, revenue_dzd: ?float, revenue_usd: ?float, profit_usd: ?float, margin_percent: ?float, note: ?string}
     */
    private function cashBasis(Model $creation, string $creationType, int $netTokens): array
    {
        if ($netTokens <= 0) {
            return [
                'available' => true,
                'revenue_dzd' => 0.0,
                'revenue_usd' => 0.0,
                'profit_usd' => $creation->getAttribute('cost_usd') !== null
                    ? round(0 - (float) $creation->getAttribute('cost_usd'), 6)
                    : 0.0,
                'margin_percent' => null,
                'note' => 'refunded_or_zero',
            ];
        }

        if (! Schema::hasTable('token_lot_allocations')) {
            return $this->unavailable('lots_not_migrated');
        }

        $rows = TokenLotAllocation::query()
            ->where('creation_type', $creationType)
            ->where('creation_id', $creation->getKey())
            ->where('kind', 'debit')
            ->with('lot')
            ->get();

        if ($rows->isEmpty()) {
            return $this->unavailable('legacy_or_free_tokens');
        }

        $dzd = 0.0;
        $usd = 0.0;
        $known = 0;
        foreach ($rows as $row) {
            $lot = $row->lot;
            if (! $lot instanceof TokenLot || ! $lot->hasCashBasis()) {
                continue;
            }
            $share = (int) $row->tokens;
            $known += $share;
            $dzd += $share * ((float) $lot->net_dzd / max(1, (int) $lot->tokens_total));
            $usd += $share * (float) $lot->usd_per_token;
        }

        if ($known <= 0) {
            return $this->unavailable('legacy_or_free_tokens');
        }
        if ($known < $netTokens) {
            return $this->unavailable('partial_legacy_mix');
        }

        $actual = $creation->getAttribute('cost_usd');
        $actual = $actual !== null && is_numeric($actual) ? (float) $actual : null;
        $profit = $actual !== null ? round($usd - $actual, 6) : null;

        return [
            'available' => true,
            'revenue_dzd' => round($dzd, 2),
            'revenue_usd' => round($usd, 6),
            'profit_usd' => $profit,
            'margin_percent' => $usd > 0 && $profit !== null ? round(($profit / $usd) * 100, 1) : null,
            'note' => null,
        ];
    }

    /**
     * @return array{available: bool, revenue_dzd: null, revenue_usd: null, profit_usd: null, margin_percent: null, note: string}
     */
    private function unavailable(string $note): array
    {
        return [
            'available' => false,
            'revenue_dzd' => null,
            'revenue_usd' => null,
            'profit_usd' => null,
            'margin_percent' => null,
            'note' => $note,
        ];
    }
}
