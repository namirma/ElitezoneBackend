<?php

namespace App\Listeners;

use App\Events\WalletFunded;
use App\Jobs\SendSmsJob;
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

            SendSmsJob::dispatch($user->mobile, $message);

            Log::info("Wallet funded SMS sent to User {$user->id}");
        } catch (\Exception $e) {
            Log::error('Failed to send wallet SMS: '.$e->getMessage());
        }
    }
}
