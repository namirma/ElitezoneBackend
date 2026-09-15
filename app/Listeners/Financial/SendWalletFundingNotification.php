<?php

namespace App\Listeners\Financial;

use App\Events\Financial\WalletFunded;
use App\Services\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendWalletFundingNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(WalletFunded $event)
    {
        $transaction = $event->transaction;
        $user = $transaction->wallet->user;

        if (! $user) {
            return;
        }

        try {
            $amount = number_format($transaction->amount);

            // ⚠️ هر منبعِ شارژ کدِ پیگیریِ خودش را دارد: درگاه `ref_id`، تأییدِ واریزِ
            // پادیوم توسط ادمین `doc_number`. خواندنِ بی‌قیدِ `meta['ref_id']` روی مسیرِ
            // پادیوم خطا می‌داد و این `try` آن را می‌بلعید — کاربر نه پیامک می‌گرفت نه
            // اعلانِ زنگوله. نبودِ کد یعنی سطرش نمی‌آید، نه «کد پیگیری: » خالی.
            $trackingCode = $transaction->meta['ref_id'] ?? $transaction->meta['doc_number'] ?? null;
            $trackingLine = $trackingCode !== null ? "کد پیگیری: {$trackingCode}\n" : '';

            Notifier::template($user, __('notifications.wallet_funded_subject'), 'wallet_funded', [
                'amount' => $amount,
                'tracking_line' => $trackingLine,
            ]);

            Log::info("Wallet funded SMS sent to User {$user->id}");
        } catch (\Exception $e) {
            Log::error('Failed to send wallet SMS: '.$e->getMessage());
        }
    }
}
