<?php

namespace App\Services\SofizPay;

use App\Models\Payment;
use App\Models\User;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One Telegram message per SofizPay checkout on the creations bot.
 * Sent when the Algérie Poste URL is ready; edited when status changes.
 */
class SofizPayPaymentTelegramNotifier
{
    private TelegramNotifier $telegram;

    public function __construct()
    {
        $this->telegram = TelegramNotifier::forCreations();
    }

    public function notifyCheckoutReady(Payment $payment): void
    {
        $this->sync($payment);
    }

    public function notifyStatusChanged(Payment $payment, ?string $hint = null): void
    {
        $this->sync($payment, $hint);
    }

    private function sync(Payment $payment, ?string $hint = null): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        try {
            $text = $this->render($payment, $hint);
            $existingId = (int) ($payment->telegram_message_id ?? 0);

            if ($existingId > 0 && $this->telegram->edit($existingId, $text)) {
                return;
            }

            $newId = $this->telegram->sendReturningId($text);
            if ($newId === null) {
                return;
            }

            Payment::query()->where('id', $payment->id)->update([
                'telegram_message_id' => $newId,
            ]);
            $payment->telegram_message_id = $newId;
        } catch (Throwable $e) {
            Log::warning('SofizPay payment Telegram notify failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }

    private function render(Payment $payment, ?string $hint): string
    {
        $user = $payment->relationLoaded('user')
            ? $payment->user
            : User::query()->find($payment->user_id);

        $status = (string) $payment->status;
        $email = (string) ($user?->email ?? '');

        $lines = [
            '<b>Token purchase</b>',
            'Status: <b>'.$this->e($status).'</b>',
            'Pack: '.$this->e((string) $payment->package_slug),
            'Tokens: '.number_format((int) $payment->tokens),
            'Amount: '.number_format((float) $payment->amount, 2).' DZD',
            'User: '.$this->e($email !== '' ? $email : '—').' (#'.(int) $payment->user_id.')',
            'Ref: '.$this->e((string) $payment->reference),
        ];

        if ($status === 'pending') {
            $lines[] = 'Checkout: waiting at Algérie Poste';
        }

        if ($status === 'paid' && $payment->paid_at) {
            $lines[] = 'Paid at: '.$this->e($payment->paid_at->timezone('UTC')->format('Y-m-d H:i:s')).' UTC';
        }

        if (($status === 'canceled' || $status === 'failed') && is_string($hint) && $hint !== '') {
            $lines[] = 'Reason: '.$this->e($this->truncate($hint, 200));
        }

        return implode("\n", $lines);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max).'…';
    }
}
