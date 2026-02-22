<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Exception;

class WalletTransactionEngine
{
    public function process(string $operation, float $amount, string $walletCode, string $status, Order $order): void
    {
        $userId = $this->resolveUserId($walletCode, $order);

        // دریافت یا ساخت کیف پول کاربر
        $wallet = Wallet::firstOrCreate(['user_id' => $userId]);

        // تعیین ستون هدف (موجودی آزاد یا بلوکه)
        $column = ($status === FinancialCodes::STATUS_AVAILABLE) ? 'balance' : 'balance_hold';

        // اعمال ریاضی
        if ($operation === '+') {
            $wallet->increment($column, $amount);
        } else {
            $wallet->decrement($column, $amount);
        }

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'order_id' => $order->id,
            'amount' => $amount,
            'type' => $operation === '+' ? 'deposit' : 'withdraw',
            'status' => $status === FinancialCodes::STATUS_AVAILABLE ? 'confirmed' : 'hold',
            'meta' => json_encode(['code' => $walletCode, 'op' => $operation])
        ]);
    }

    private function resolveUserId(string $code, Order $order): int
    {
        return match ($code) {
            FinancialCodes::WALLET_OWNER => $order->placer_user_id,
            FinancialCodes::WALLET_SUPPLIER => $order->beneficiary_user_id,
            FinancialCodes::WALLET_SAZE => 1, // آی‌دی ادمین سیستم
            default => throw new Exception("کد کیف پول ناشناخته wallet: $code"),
        };
    }
}
