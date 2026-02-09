<?php

namespace App\Jobs;

use App\Constants\FinancialCodes;
use App\Models\WalletTransaction;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckSupplierBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SmsService $smsService): void
    {
        $heldTransactions = WalletTransaction::query()
            ->where('status', FinancialCodes::STATUS_HOLD)
            ->where('type', 'deposit')
            ->with(['order.items', 'wallet.user'])
            ->get();

        foreach ($heldTransactions as $transaction) {
            try {
                $this->processTransaction($transaction, $smsService);
            } catch (\Exception $e) {
                Log::error("Error releasing TRX {$transaction->id}: " . $e->getMessage());
            }
        }
    }

    private function processTransaction(WalletTransaction $transaction, SmsService $smsService): void
    {
        $order = $transaction->order;
        if (!$order) return;

        $firstItem = $order->items->first();
        if (!$firstItem) return;

        // پیدا کردن سوپلایر
        $supplier = $firstItem->supplier;
        if (!$supplier && $firstItem->supplier_user_id) {
            $supplier = \App\Models\User::find($firstItem->supplier_user_id);
        }

        if (!$supplier || !$supplier->wallet) return;

        // شرط اصلی: موجودی مثبت
        if ($supplier->wallet->balance >= 0) {

            // *** تغییر مهم: اضافه کردن $order به use ***
            DB::transaction(function () use ($transaction, $supplier, $smsService, $order) {

                // آزادسازی تراکنش
                $transaction->update([
                    'status' => FinancialCodes::STATUS_AVAILABLE
                ]);

                // آزادسازی پول در کیف پول خریدار
                $buyerWallet = $transaction->wallet;
                $buyerWallet->decrement('balance_hold', $transaction->amount);
                $buyerWallet->increment('balance', $transaction->amount);

                // ارسال پیامک
                $buyer = $buyerWallet->user;
                if ($buyer && $buyer->mobile) {
                    $smsService->send(
                        $buyer->mobile,
                        "مبلغ " . number_format($transaction->amount) . " ریال بابت سفارش {$order->order_number} آزاد شد."
                    );
                }

                Log::info("Released funds for TRX {$transaction->id} (Order {$order->id})");
            });
        }
    }
}
