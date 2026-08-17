<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the deficit leg of a weighbridge result.
 *
 * Called only when POOM < POO (under-delivery). There are two outcomes:
 *   - Supplier covers the delta immediately → buyer refund lands as AVAILABLE.
 *   - Supplier cannot cover now → buyer refund is parked in HOLD and the order
 *     is flagged for CheckSupplierBalancesJob to release later.
 *
 * Must be called inside an active DB::transaction with a locked Order.
 * Returns the status string for the orchestrator response.
 */
class SupplierDebtHandler
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine,
    ) {}

    /**
     * @return 'deficit'|'deficit_pending_supplier'
     */
    public function handle(Order $order, float $refundAmount, float $reclaimAmount): string
    {
        $supplierId = (int) $order->items->first()->supplier_user_id;

        // Pessimistic lock on the supplier wallet to avoid a race with other jobs.
        $supplierWallet = Wallet::where('user_id', $supplierId)->lockForUpdate()->first();
        $supplierCanCover = $supplierWallet && ((float) $supplierWallet->balance >= $reclaimAmount);

        if ($supplierCanCover) {
            return $this->settleImmediately($order, $refundAmount, $reclaimAmount, $supplierId);
        }

        return $this->parkDebtAndNotify($order, $refundAmount, $reclaimAmount);
    }

    /**
     * ⚠️ ترتیب legها باربر است — تغییرش ندهید.
     *
     * پول باید **سوپلایر ← سازه ← خریدار** حرکت کند، به همین ترتیب.
     *
     * قبلاً `debitSystem($refundAmount)` بالای `handle()` و پیش از هر انشعابی صدا
     * زده می‌شد، یعنی سازه اول پول می‌داد و بعد از سوپلایر پس می‌گرفت. ولی سازه در
     * لحظه‌ی پرداخت POO را گرفته و بلافاصله PTS را پرداخته، پس تنها چیزی که نگه
     * می‌دارد کمیسیون است (`POO − PTS`) — مبلغی کوچک‌تر از عودتِ کسری در حالت
     * معمول. نتیجه: `InsufficientBalanceException` و rollback شدنِ **کلِ باسکول**،
     * یعنی هیچ کم‌تحویلی‌ای در سیستم قابل ثبت نبود؛ آن هم با پیام
     * «موجودی کیف پول کافی نیست» که انگار مشکلِ خریدار است.
     *
     * (تست `ProcessWeighbridgeResultActionTest` این را نمی‌گرفت چون کیف پول سازه را
     *  دستی شارژ می‌کرد — همان کاری که در محیط واقعی هیچ‌کس نمی‌کند.)
     *
     * همین قاعده در مسیر کوپن هم مستند است: هیچ legی نباید WSSG را منفی کند.
     */
    private function settleImmediately(
        Order $order,
        float $refundAmount,
        float $reclaimAmount,
        int $supplierId,
    ): string {
        // ۱) اول از سوپلایر پس بگیر — منبعِ واقعیِ این پول، اضافه‌دریافتیِ اوست.
        $this->walletEngine->debitSupplier(
            $order, $reclaimAmount, FinancialCodes::STATUS_AVAILABLE, $supplierId, 'کسر بابت کسری باسکول'
        );
        $this->walletEngine->creditSystem(
            $order, $reclaimAmount, FinancialCodes::STATUS_AVAILABLE,
            "بازپس‌گیری از تامین‌کننده بابت کسری باسکول سفارش {$order->order_number}"
        );

        // ۲) حالا که سازه تأمین شده، به خریدار برگردان. مابه‌التفاوتِ
        //    `refundAmount − reclaimAmount` همان کمیسیونی است که سازه باید از
        //    سهم خودش برگرداند و در لحظه‌ی پرداخت نگه داشته است.
        $this->walletEngine->debitSystem(
            $order, $refundAmount, FinancialCodes::STATUS_AVAILABLE,
            "پرداخت وجه کسری باسکول سفارش {$order->order_number} به خریدار"
        );
        $this->walletEngine->creditBuyer(
            $order, $refundAmount, FinancialCodes::STATUS_AVAILABLE, 'بازگشت وجه ناشی از کسری باسکول'
        );

        return 'deficit';
    }

    private function parkDebtAndNotify(
        Order $order,
        float $refundAmount,
        float $reclaimAmount,
    ): string {
        // **هیچ پولی اینجا جابه‌جا نمی‌شود** (تصمیم مدیریت، دور ۲ پرسش ۲/ب).
        //
        // پیش از این سازه مبلغ عودت را جلو می‌انداخت (`debitSystem`) و به هولدِ
        // خریدار می‌ریخت. ولی کیف پول سازه پس از پرداخت فقط کمیسیون را دارد و
        // عودت از آن بزرگ‌تر است، پس آن برداشت می‌شکست و کلِ تراکنشِ باسکول —
        // از جمله همین پارک — rollback می‌شد. نتیجه: با کیف پولِ واقعاً خالیِ
        // تأمین‌کننده **هیچ کسری‌ای قابل ثبت نبود**.
        //
        // حالا فقط دو عدد ثبت می‌شود: طلب از تأمین‌کننده و بدهی به خریدار.
        // پولی که وجود ندارد منتقل هم نمی‌شود، پس دفتر متوازن می‌ماند و
        // `CheckSupplierBalancesJob` با شارژِ بعدی هر دو لِگ را با هم انجام
        // می‌دهد.
        $order->forceFill([
            Order::SUPPLIER_DEBT_PENDING => true,
            Order::SUPPLIER_DEBT_AMOUNT => $reclaimAmount,
            Order::BUYER_REFUND_PENDING_AMOUNT => $refundAmount,
        ])->save();

        // Queue the SMS and defer it until AFTER the surrounding weighbridge transaction
        // commits. The previous synchronous SmsService::send() was a blocking HTTP POST
        // executed while the order + wallet rows were lockForUpdate-held — a slow TSMS
        // endpoint would hold those locks for the whole network round-trip, and a later
        // rollback would have already told the supplier about a debt that never landed.
        $supplierUser = $order->items->first()?->supplier;
        if ($supplierUser?->mobile) {
            SendSmsJob::dispatch(
                $supplierUser->mobile,
                __('sms.supplier_debt_notification', [
                    'order_number' => $order->order_number,
                    'amount' => number_format((int) $reclaimAmount),
                ])
            )->afterCommit();
        }

        Log::channel('financial')->warning(
            "Supplier {$supplierUser?->id} insufficient balance for deficit reclaim on order {$order->id}. Debt: {$reclaimAmount}"
        );

        return 'deficit_pending_supplier';
    }
}
