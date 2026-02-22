<?php

namespace App\Jobs;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\WalletTransactionEngine;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckReservationExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WalletTransactionEngine $walletEngine): void
    {
        
        $expiredOrders = Order::query()
            ->where('status', 'reserved')
            ->whereNotNull('validation_of_reserve')
            ->where('validation_of_reserve', '<', Carbon::now())
            ->get();

        foreach ($expiredOrders as $order) {
            $this->expireOrder($order, $walletEngine);
        }
    }

    private function expireOrder(Order $order, WalletTransactionEngine $walletEngine): void
    {
        DB::transaction(function () use ($order, $walletEngine) {
            $ptr = $order->payment_to_reserve;

            // عملیات بازگشت وجه (Reversal)

            // 1. برداشت از بلوکه شرکت: -(PTR, WSSG, H)
            $walletEngine->process('-', $ptr, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_HOLD, $order);

            // 2. واریز به آزاد خریدار: +(PTR, WO, A)
            $walletEngine->process('+', $ptr, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order);

            // 3. ابطال سفارش
            $order->update(['status' => 'cancelled']);

            // صدور سند ابطال (i و j)
            // GenerateDocumentJob::dispatch($order, FinancialCodes::DOC_REFUND_RECEIPT);

            Log::info("رزرو سفارش {$order->id} به دلیل انقضای VOR لغو شد.");
        });
    }
}
