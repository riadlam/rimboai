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

        [$title, $subtitle, $statusEmoji] = match ($status) {
            'paid' => ['🎉 <b>Token purchase — paid!</b>', 'tokens just landed in their wallet', '🟢'],
            'canceled' => ['😅 <b>Token purchase — canceled</b>', 'they bailed at Algérie Poste', '🟠'],
            'failed' => ['😵 <b>Token purchase — failed</b>', 'the bank said nope', '🔴'],
            default => ['🛒 <b>Token purchase — checkout live</b>', 'waiting at Algérie Poste', '🟡'],
        };

        $lines = [
            $title,
            '<i>'.$subtitle.'</i>',
            $this->hr(),
            $statusEmoji.' Status: <b>'.$this->e($status).'</b>',
            '📦 Pack · <b>'.$this->e((string) $payment->package_slug).'</b>',
            '🪙 Tokens · <b>'.number_format((int) $payment->tokens).'</b>',
            '💵 Amount · <b>'.number_format((float) $payment->amount, 2).' DZD</b>',
            '👤 User · '.$this->e($email !== '' ? $email : '—').' <code>#'.(int) $payment->user_id.'</code>',
            '🧾 Ref · <code>'.$this->e((string) $payment->reference).'</code>',
        ];

        if ($status === 'pending') {
            $lines[] = $this->hr();
            $lines[] = '⏳ Checkout: waiting at Algérie Poste';
        }

        if ($status === 'paid' && $payment->paid_at) {
            $lines[] = $this->hr();
            $lines[] = '✅ Paid at · '.$this->e($payment->paid_at->timezone('UTC')->format('Y-m-d H:i:s')).' UTC';
        }

        if (($status === 'canceled' || $status === 'failed') && is_string($hint) && $hint !== '') {
            $lines[] = $this->hr();
            $lines[] = '💬 Reason · '.$this->e($this->truncate($hint, 200));
        }

        return implode("\n", $lines);
    }

    private function hr(): string
    {
        return '<i>────────────</i>';
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
