<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletTransactionEngine
{
    /**
     * پردازش تراکنش کیف پول با مکانیزم قفل (Pessimistic Locking)
     */
    public function process(
        string $operation,
        float $amount,
        string $walletCode,
        string $status,
        Order $order,
        ?string $description = null,
        ?int $targetUserId = null
    ): void {
        // اجرای کل عملیات در یک تراکنش برای حفظ یکپارچگی داده‌ها
        DB::transaction(function () use ($operation, $amount, $walletCode, $status, $order, $description, $targetUserId) {

            // 1. تعیین کاربر هدف
            $userId = $targetUserId ?? $this->resolveUserId($walletCode, $order);

            $wallet = Wallet::firstOrCreate(['user_id' => $userId]);
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            // 3. تعیین نوع عملیات
            $type = ($operation === '+') ? 'deposit' : 'withdraw';

            $isHold = ($status === FinancialCodes::STATUS_HOLD || $status === 'hold');

            if ($operation === '+') {
                if ($isHold) {
                    $wallet->balance_hold += $amount;
                } else {
                    $wallet->balance += $amount;
                }
            } else {
                if ($isHold) {
                    if ($wallet->balance_hold < $amount) {
                        throw new InsufficientBalanceException($amount, (float) $wallet->balance_hold);
                    }
                    $wallet->balance_hold -= $amount;
                } else {
                    if ($wallet->balance < $amount) {
                        throw new InsufficientBalanceException($amount, (float) $wallet->balance);
                    }
                    $wallet->balance -= $amount;
                }
            }

            $wallet->save();

            // 5. ثبت تراکنش در جدول تاریخچه
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'type' => $type,
                'status' => $status,
                'description' => $description,
                // WalletTransaction casts `meta` to array — pass a plain array and let
                // Eloquent encode it once. json_encode() here would double-encode it.
                'meta' => ['code' => $walletCode, 'op' => $operation],
            ]);
        });
    }

    /**
     * Release an escrow hold back to available balance, append-only.
     *
     * Atomically moves `balance_hold → balance` AND records a distinct
     * `TYPE_HOLD_RELEASE` WalletTransaction. Unlike mutating the original hold
     * row's status, this keeps the ledger immutable: the hold (placement) and the
     * release are two timestamped events that net to zero, so the hold history is
     * always reconstructable for audit.
     *
     * @param  WalletTransaction|null  $sourceHold  the original hold row, linked in meta for traceability.
     */
    public function releaseHold(
        int $userId,
        float $amount,
        Order $order,
        ?string $description = null,
        ?WalletTransaction $sourceHold = null,
    ): WalletTransaction {
        return DB::transaction(function () use ($userId, $amount, $order, $description, $sourceHold) {
            $wallet = Wallet::firstOrCreate(['user_id' => $userId]);
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            if ((float) $wallet->balance_hold < $amount) {
                throw new InsufficientBalanceException($amount, (float) $wallet->balance_hold);
            }

            $wallet->balance_hold -= $amount;
            $wallet->balance += $amount;
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'type' => WalletTransaction::TYPE_HOLD_RELEASE,
                'status' => FinancialCodes::STATUS_AVAILABLE,
                'description' => $description,
                'meta' => [
                    'op' => 'hold_release',
                    'source_hold_id' => $sourceHold?->id,
                ],
            ]);
        });
    }

    /**
     * تشخیص آیدی کاربر بر اساس کد کیف پول
     *
     * Note: callers that know the target user ID up-front should always pass
     * $targetUserId explicitly to process() — this fallback is a safety-net.
     *
     * WALLET_CONSULTANT → beneficiary_user_id (the consultant on the order)
     * WALLET_VAT        → financial.vat_user_id (separate VAT escrow user; P5)
     */
    private function resolveUserId(string $code, Order $order): int
    {
        return match ($code) {
            FinancialCodes::WALLET_OWNER => (int) $order->placer_user_id,

            // Supplier user is stored on order items, not the order itself;
            // callers must always pass $targetUserId explicitly for WALLET_SUPPLIER.
            FinancialCodes::WALLET_SUPPLIER => (int) ($order->supplier_user_id
                ?? throw new \RuntimeException("WALLET_SUPPLIER: targetUserId must be supplied explicitly (order {$order->id})")),

            FinancialCodes::WALLET_SAZE => (int) config('financial.system_user_id', 1),

            // Consultant commission wallet — beneficiary_user_id is the consultant.
            FinancialCodes::WALLET_CONSULTANT => (int) ($order->beneficiary_user_id
                ?? throw new \RuntimeException("WALLET_CONSULTANT: order {$order->id} has no consultant (beneficiary_user_id is null)")),

            // VAT escrow wallet — uses dedicated vat_user_id when configured (P5),
            // falls back to system_user_id so the system stays correct before
            // the env var is deployed.
            FinancialCodes::WALLET_VAT => (int) config('financial.vat_user_id', config('financial.system_user_id', 1)),

            default => throw new \RuntimeException("کد کیف پول ناشناخته: $code"),
        };
    }
}
