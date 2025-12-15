<?php

namespace App\Services\Payment\Gateways;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Exceptions\Wallet\InsufficientBalanceException;
use Exception;

class WalletGateway implements PaymentGatewayInterface
{
    public function getName(): string
    {
        return 'wallet';
    }

    public function pay(User $user, Order $order): array
    {
        // 1. دریافت کیف پول با قفل
        $wallet = $user->wallet()->lockForUpdate()->first();

        if (!$wallet) {
            throw new Exception(__('wallet.errors.not_found'));
        }

        // 2. بررسی موجودی
        if ($wallet->balance < $order->final_amount) {
            throw new InsufficientBalanceException(__('wallet.errors.insufficient_balance'));
        }

        // 3. کسر موجودی
        $wallet->decrement('balance', $order->final_amount);

        // 4. ثبت تراکنش
        $transaction = $wallet->transactions()->create([
            WalletTransaction::AMOUNT      => -1 * $order->final_amount,
            WalletTransaction::TYPE        => WalletTransaction::TYPE_WITHDRAW,
            WalletTransaction::STATUS      => WalletTransaction::STATUS_CONFIRMED,
            WalletTransaction::DESCRIPTION => __('wallet.descriptions.payment_order', ['order_number' => $order->order_number]),
            WalletTransaction::META        => ['order_id' => $order->id],
            'balance_before' => $wallet->balance + $order->final_amount,
            'balance_after'  => $wallet->balance,
        ]);

        return [
            'transaction_id' => $transaction->id,
            'reference_id'   => null, // کیف پول رفنس بانکی ندارد
        ];
    }
}
