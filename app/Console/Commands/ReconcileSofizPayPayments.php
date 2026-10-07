<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\SofizPay\SofizPayFulfillmentService;
use Illuminate\Console\Command;

/**
 * Safety net for the case where a user pays on SATIM but never returns to the
 * return URL (closed the tab, lost connection, etc.).
 *
 * This re-verifies every pending SofizPay payment server-to-server. Bank-paid
 * rows move to status=review (Telegram Accept/Decline); tokens are NOT credited
 * here. Rows already in review are left alone.
 *
 * Pending (not review) rows older than the lookback window that never settled
 * are marked canceled (abandoned) so Telegram and billing history stop showing
 * them as live.
 */
class ReconcileSofizPayPayments extends Command
{
    protected $signature = 'payments:reconcile-sofizpay
        {--hours=48 : Only look at pending payments created within the last N hours}
        {--limit=200 : Maximum number of payments to process in one run}';

    protected $description = 'Verify pending SofizPay payments and move bank-paid ones into review';

    public function handle(SofizPayFulfillmentService $fulfillment): int
    {
        $hours = (int) $this->option('hours');
        $limit = (int) $this->option('limit');

        $payments = Payment::query()
            ->where('provider', 'sofizpay')
            ->where('status', 'pending')
            ->whereNotNull('cib_order_number')
            ->where('cib_order_number', '!=', '')
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $inReview = 0;
        $stillPending = 0;
        $canceled = 0;
        $failed = 0;
        $errors = 0;

        if ($payments->isNotEmpty()) {
            $this->info("Reconciling {$payments->count()} pending payment(s)...");
        }

        foreach ($payments as $payment) {
            try {
                $result = $fulfillment->verifyAndFulfill($payment);
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  #{$payment->id} ({$payment->reference}): {$e->getMessage()}");
                report($e);

                continue;
            }

            $freshStatus = (string) ($payment->fresh()?->status ?? '');

            switch ($result['status']) {
                case 'pending':
                    if ($freshStatus === 'review') {
                        $inReview++;
                        $this->line("  #{$payment->id} ({$payment->reference}): review — awaiting Accept");
                    } else {
                        $stillPending++;
                    }
                    break;
                case 'success':
                    // Should not credit from reconcile anymore; log if somehow paid.
                    $this->line("  #{$payment->id} ({$payment->reference}): already paid");
                    break;
                case 'canceled':
                    $canceled++;
                    $this->line("  #{$payment->id} ({$payment->reference}): canceled");
                    break;
                case 'failed':
                    $failed++;
                    $this->line("  #{$payment->id} ({$payment->reference}): failed");
                    break;
                default:
                    $errors++;
                    $this->warn("  #{$payment->id} ({$payment->reference}): {$result['message']}");
                    break;
            }
        }

        // Never abandon review rows — bank already took the money.
        $stale = Payment::query()
            ->where('provider', 'sofizpay')
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subHours($hours))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $reason = 'No payment within '.$hours.' hours.';
        foreach ($stale as $payment) {
            try {
                $fulfillment->abandonStale($payment, $reason);
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  #{$payment->id} ({$payment->reference}): {$e->getMessage()}");
                report($e);

                continue;
            }

            if ($payment->fresh()?->status === 'canceled') {
                $canceled++;
                $this->line("  #{$payment->id} ({$payment->reference}): abandoned");
            }
        }

        if ($payments->isEmpty() && $stale->isEmpty()) {
            $this->info('No pending SofizPay payments to reconcile.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Done. in_review={$inReview} still_pending={$stillPending} canceled={$canceled} failed={$failed} errors={$errors}");

        return self::SUCCESS;
    }
}
