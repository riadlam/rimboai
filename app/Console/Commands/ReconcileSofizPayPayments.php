<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\SofizPay\SofizPayFulfillmentService;
use Illuminate\Console\Command;

/**
 * Safety net for the case where a user pays on SATIM but never returns to the
 * return URL (closed the tab, lost connection, etc.). Without this, the money
 * is taken but the tokens are never credited.
 *
 * This re-verifies every pending SofizPay payment server-to-server and credits
 * tokens through the same idempotent path used by the browser return handler,
 * so a payment can never be fulfilled twice.
 */
class ReconcileSofizPayPayments extends Command
{
    protected $signature = 'payments:reconcile-sofizpay
        {--hours=48 : Only look at pending payments created within the last N hours}
        {--limit=200 : Maximum number of payments to process in one run}';

    protected $description = 'Verify pending SofizPay payments and credit tokens for any that were actually paid';

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

        if ($payments->isEmpty()) {
            $this->info('No pending SofizPay payments to reconcile.');

            return self::SUCCESS;
        }

        $this->info("Reconciling {$payments->count()} pending payment(s)...");

        $paid = 0;
        $stillPending = 0;
        $errors = 0;

        foreach ($payments as $payment) {
            try {
                $result = $fulfillment->verifyAndFulfill($payment);
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  #{$payment->id} ({$payment->reference}): {$e->getMessage()}");
                report($e);

                continue;
            }

            switch ($result['status']) {
                case 'success':
                    $paid++;
                    $note = $result['credited'] ? 'credited' : 'already credited';
                    $this->line("  #{$payment->id} ({$payment->reference}): paid — {$note}");
                    break;
                case 'failed':
                    $stillPending++;
                    break;
                default:
                    $errors++;
                    $this->warn("  #{$payment->id} ({$payment->reference}): {$result['message']}");
                    break;
            }
        }

        $this->newLine();
        $this->info("Done. paid={$paid} still_pending={$stillPending} errors={$errors}");

        return self::SUCCESS;
    }
}
