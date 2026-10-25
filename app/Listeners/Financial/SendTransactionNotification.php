<?php

namespace App\Listeners\Financial;

use App\Events\Financial\TransactionProcessed;
use App\Models\WalletTransaction;
use App\Services\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendTransactionNotification implements ShouldQueue
{
    use InteractsWithQueue;

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
        Notifier::toUser($user, __('notifications.transaction_subject'), $msg);
    }
}
