<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\WalletTransactionEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessReservationPaymentAction
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine
    ) {}

    /**
     * اجرای فلوچارت پرداخت رزرو (PTR)
     * @param Order $order
     * @param float $ptrAmount مبلغ رزرو
     * @param int $validityHours مدت اعتبار 
     */
    public function execute(Order $order, float $ptrAmount, int $validityHours = 2): void
    {
        Log::info("شروع فرآیند رزرو سفارش {$order->id} با مبلغ {$ptrAmount}");

        DB::transaction(function () use ($order, $ptrAmount, $validityHours) {

            // 1. ثبت اطلاعات PTR و VOR (طبق عکس 402)
            $vor = Carbon::now()->addHours($validityHours);

            $order->update([
                Order::PAYMENT_TO_RESERVE => $ptrAmount,
                Order::VALIDATION_OF_RESERVE => $vor,
                'status' => 'reserved', // وضعیت: رزرو شده
            ]);

            // 2. عملیات مالی (طبق عکس 403)

            // الف) کسر از خریدار: -(PTR, WO, A)
            // پول از موجودی "آزاد" خریدار کم می‌شود
            $this->walletEngine->process(
                '-',
                $ptrAmount,
                FinancialCodes::WALLET_OWNER,
                FinancialCodes::STATUS_AVAILABLE,
                $order
            );

            // ب) واریز به شرکت: +(PTR, WSSG, H)
            // پول به موجودی "بلوکه" شرکت اضافه می‌شود (چون هنوز درآمد قطعی نیست)
            $this->walletEngine->process(
                '+',
                $ptrAmount,
                FinancialCodes::WALLET_SAZE,
                FinancialCodes::STATUS_HOLD,
                $order
            );

            // 3. صدور اسناد (طبق ستون‌های رنگی دیاگرام)
            // سند E: رسید خریدار
            // GenerateDocumentJob::dispatch($order, FinancialCodes::DOC_RESERVE_RECEIPT);

            // سند F: گزارش برای سوپلایر
            // GenerateDocumentJob::dispatch($order, FinancialCodes::DOC_RESERVE_REPORT);

            Log::info("سفارش {$order->id} تا تاریخ {$vor} رزرو شد.");
        });
    }
}
