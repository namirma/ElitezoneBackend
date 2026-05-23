<?php

namespace App\Listeners\Financial;

use App\Events\Financial\TransactionProcessed;
use App\Jobs\Notification\SendSmsJob;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendTransactionNotification implements ShouldQueue
{
    public function handle(TransactionProcessed $event)
    {
        $trx = $event->transaction;
        $user = $trx->wallet->user;

        if (! $user) {
            return;
        }

        $amount = number_format($trx->amount);

        $typeFa = match ($trx->type) {
            WalletTransaction::TYPE_DEPOSIT => 'واریز',
            WalletTransaction::TYPE_WITHDRAW => 'برداشت',
            default => 'تراکنش'
        };

        if ($trx->status === WalletTransaction::STATUS_CONFIRMED) {
            $msg = __('sms.transaction_confirmed', ['type' => $typeFa, 'amount' => $amount]);
        } else {
            $msg = __('sms.transaction_rejected', ['type' => $typeFa, 'amount' => $amount]);
        }

        // ارسال به صف پیامک
        SendSmsJob::dispatch($user->mobile, $msg);
    }
}
