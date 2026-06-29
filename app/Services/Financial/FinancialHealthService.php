<?php

namespace App\Services\Financial;

use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;

class FinancialHealthService
{
    /**
     * گزارش جامع سلامت مالی پلتفرم (6 aggregate query).
     * این متد در کنترلر استاتیک صدا زده نمی‌شود؛ DI انجام می‌شود.
     */
    public function getReport(): array
    {
        $systemUserId = (int) config('financial.system_user_id', 1);
        $vatUserId    = (int) config('financial.vat_user_id', $systemUserId);

        $systemWallet = Wallet::where(Wallet::USER_ID, $systemUserId)->first();
        $vatWallet    = ($vatUserId !== $systemUserId)
            ? Wallet::where(Wallet::USER_ID, $vatUserId)->first()
            : null;

        $platformBalance = (float) Wallet::sum(Wallet::BALANCE);
        $platformHold    = (float) Wallet::sum(Wallet::BALANCE_HOLD);

        $pendingDepositAmount    = (float) WalletTransaction::where(WalletTransaction::TYPE, WalletTransaction::TYPE_DEPOSIT)
            ->where(WalletTransaction::STATUS, WalletTransaction::STATUS_PENDING)
            ->sum(WalletTransaction::AMOUNT);

        $pendingWithdrawalAmount = (float) WalletTransaction::where(WalletTransaction::TYPE, WalletTransaction::TYPE_WITHDRAW)
            ->where(WalletTransaction::STATUS, WalletTransaction::STATUS_PENDING)
            ->sum(WalletTransaction::AMOUNT);

        $supplierDebtCount  = Order::where(Order::SUPPLIER_DEBT_PENDING, true)->count();
        $supplierDebtAmount = (float) Order::where(Order::SUPPLIER_DEBT_PENDING, true)->sum(Order::SUPPLIER_DEBT_AMOUNT);

        return [
            'system_wallet' => $systemWallet ? [
                'balance'      => (float) $systemWallet->{Wallet::BALANCE},
                'balance_hold' => (float) $systemWallet->{Wallet::BALANCE_HOLD},
            ] : null,
            'vat_wallet' => $vatWallet ? [
                'balance'      => (float) $vatWallet->{Wallet::BALANCE},
                'balance_hold' => (float) $vatWallet->{Wallet::BALANCE_HOLD},
            ] : null,
            'platform_totals' => [
                'total_balance'             => $platformBalance,
                'total_hold'                => $platformHold,
                'pending_deposit_amount'    => $pendingDepositAmount,
                'pending_withdrawal_amount' => $pendingWithdrawalAmount,
            ],
            'supplier_debts' => [
                'count'  => $supplierDebtCount,
                'amount' => $supplierDebtAmount,
            ],
        ];
    }
}
