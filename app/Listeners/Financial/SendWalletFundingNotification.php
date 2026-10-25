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
            $message = "کیف پول شما مبلغ {$amount} ریال شارژ شد.\nکد پیگیری: {$transaction->meta['ref_id']}\nالیت زون";

            Notifier::toUser($user, __('notifications.wallet_funded_subject'), $message);

            Log::info("Wallet funded SMS sent to User {$user->id}");
        } catch (\Exception $e) {
            Log::error('Failed to send wallet SMS: '.$e->getMessage());
        }
    }
}
