<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\Invoice\InvoiceGenerator;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessReservationPaymentAction
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine,
        protected InvoiceGenerator $invoiceGenerator,
    ) {}

    /**
     * @param  CarbonInterface  $deadline  مهلتِ رزرو، از `ReserveDeadlineResolver`.
     * @param  int  $graceHours  اسنپ‌شاتِ ارفاق (الحاقیه‌ی ۱، تغییر ۳).
     */
    public function execute(Order $order, float $reserveAmount, CarbonInterface $deadline, int $graceHours): void
    {
        if ($this->isAlreadyProcessed($order)) {
            Log::channel('financial')->warning("ProcessReservationPaymentAction skipped: order {$order->id} already reserved.");

            return;
        }

        if ($reserveAmount <= 0) {
            throw new \InvalidArgumentException('مبلغ رزرو باید بزرگ‌تر از صفر باشد.');
        }

        DB::transaction(function () use ($order, $reserveAmount, $deadline, $graceHours) {

            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            if ($this->isAlreadyProcessed($locked)) {
                Log::channel('financial')->warning("Race detected: order {$locked->id} already reserved.");

                return;
            }

            $this->walletEngine->debitBuyer($locked, $reserveAmount, FinancialCodes::STATUS_AVAILABLE, "پرداخت مبلغ بیعانه برای رزرو سفارش {$locked->order_number}");

            $this->walletEngine->creditSystem($locked, $reserveAmount, FinancialCodes::STATUS_HOLD, "امانت مبلغ رزرو سفارش {$locked->order_number}");

            $locked->forceFill([
                Order::STATUS => Order::STATUS_RESERVED,
                Order::PAYMENT_TO_RESERVE => $reserveAmount,
                // هر سه با هم نوشته می‌شوند: مهلتِ جاری، مهلتِ اولیه (که هرگز
                // عوض نمی‌شود و مبنای پنجره‌ی لغو خریدار است) و اسنپ‌شاتِ ارفاق.
                Order::RESERVE_DEADLINE => $deadline,
                Order::RESERVE_DEADLINE_ORIGINAL => $deadline,
                Order::RESERVE_GRACE_HOURS => $graceHours,
                // ستون بازنشسته — تا فاز ۱۴ هم‌زمان پر می‌شود تا مصرف‌کننده‌های
                // پورت‌نشده (گزارش‌های ادمین) بی‌صدا خالی نبینند.
                Order::RESERVATION_EXPIRES_AT => $deadline,
            ])->save();

            // ── صدور اسناد مالی رزرو (E + F) ────────────────────────────
            $this->invoiceGenerator->generateReservationDocs($locked);

            Log::channel('financial')->info("سفارش {$locked->order_number} رزرو شد.");
        }, 3);
    }

    private function isAlreadyProcessed(Order $order): bool
    {
        return $order->status === Order::STATUS_RESERVED;
    }
}
