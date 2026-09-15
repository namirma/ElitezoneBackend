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

        $key = $trx->status === WalletTransaction::STATUS_CONFIRMED
            ? 'transaction_confirmed'
            : 'transaction_rejected';

        // ارسال به صف پیامک
        Notifier::template($user, __('notifications.transaction_subject'), $key, ['type' => $typeFa, 'amount' => $amount]);
    }
}
