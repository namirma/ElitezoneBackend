<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Actions\Financial\ProcessReservationPaymentAction;
use App\Actions\Financial\ProcessReservationCompletionAction;
use App\Constants\FinancialCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\PayFullOrderRequest;
use App\Http\Requests\PayHoldFeeRequest;
use App\Http\Requests\UploadOrderReceiptRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Exception;
use Illuminate\Http\JsonResponse;

class OrderPaymentController extends Controller
{
    /**
     * آستانه مبلغ برای فعال شدن امکان رزرو
     * معادل ۱۹۵ میلیون تومان (۱,۹۵۰,۰۰۰,۰۰۰ ریال)
     */
    private const RESERVATION_THRESHOLD = 1950000000;

    public function __construct(
        protected ProcessPreScalePaymentAction $purchaseAction,           // خرید مستقیم
        protected ProcessReservationPaymentAction $reservationAction,     // شروع رزرو
        protected ProcessReservationCompletionAction $completionAction    // تکمیل وجه رزرو
    ) {}

    /**
     * متد اصلی پرداخت (Main Entry Point)
     * مدیریت خرید مستقیم، شروع رزرو و تکمیل وجه
     */
    public function payFullOrder(PayFullOrderRequest $request, Order $order): JsonResponse
    {
        // 1. بررسی دسترسی کاربر به این سفارش
        $this->authorize('pay', $order);

        // 2. بررسی وضعیت قابل پرداخت بودن سفارش
        $payableStatuses = [Order::STATUS_PENDING, Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED];
        if (! in_array($order->status, $payableStatuses)) {
            return error(__('orders.cannot_pay_order'), [], 400);
        }

        try {
            // =================================================================
            // سناریو ۱: تکمیل وجه سفارشی که قبلاً رزرو شده (Completion)
            // =================================================================
            if (in_array($order->status, [Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED])) {

                $this->completionAction->execute($order);

                return success([
                    'order'     => new OrderResource($order->fresh()),
                    'documents' => [
                        FinancialCodes::DOC_FINAL_RECEIPT_OWNER,    // G
                        FinancialCodes::DOC_FINAL_REPORT_SUPPLIER,  // H
                        FinancialCodes::DOC_LOADING_ORDER           // C
                    ],
                    'message'   => 'تکمیل وجه رزرو با موفقیت انجام شد. اسناد G و H صادر شدند.'
                ]);
            }

            // =================================================================
            // سناریو ۲: سفارش جدید (خرید مستقیم یا شروع رزرو)
            // =================================================================

            // محاسبه قیمت کل برای شرط رزرو (اولویت با قیمت رسمی در صورت وجود)
            // نکته: اگر قیمت رسمی موجود است، باید مالیات هم به آن اضافه شود تا با آستانه قابل مقایسه باشد
            // فرض: final_amount شامل همه چیز (حمل + ارزش افزوده) است و دقیق‌ترین عدد است.
            $totalPriceToCheck = $order->final_amount;

            $userWantsReserve = $request->input('payment_type') === 'reserve';

            // --- حالت الف: مبلغ زیر ۱۹۵ میلیون (فقط خرید مستقیم مجاز است) ---
            if ($totalPriceToCheck < self::RESERVATION_THRESHOLD) {

                if ($userWantsReserve) {
                    return error("برای مبالغ زیر ۱۹۵ میلیون تومان امکان رزرو وجود ندارد.", [], 400);
                }

                // انجام خرید مستقیم (آنی)
                $this->purchaseAction->execute($order);

                return success([
                    'order'     => new OrderResource($order->fresh()),
                    'documents' => [
                        FinancialCodes::DOC_RECEIPT_OWNER,   // A
                        FinancialCodes::DOC_REPORT_SUPPLIER, // B
                        FinancialCodes::DOC_LOADING_ORDER    // C
                    ],
                    'message'   => 'پرداخت کامل انجام شد.'
                ]);
            }

            // --- حالت ب: مبلغ بالای ۱۹۵ میلیون ---
            if ($userWantsReserve) {
                // >> انتخاب کاربر: رزرو

                // مبلغ بیعانه: دقیقاً ۱۹۵ میلیون تومان (۱,۹۵۰,۰۰۰,۰۰۰ ریال)
                $ptrAmount = self::RESERVATION_THRESHOLD;

                $this->reservationAction->execute($order, $ptrAmount, validityHours: 2);

                return success([
                    'order'     => new OrderResource($order->fresh()),
                    'documents' => [
                        FinancialCodes::DOC_RESERVE_RECEIPT, // E
                        FinancialCodes::DOC_RESERVE_REPORT   // F
                    ],
                    'message'   => 'سفارش رزرو شد. اسناد E و F صادر گردید.'
                ]);

            } else {
                // >> انتخاب کاربر: خرید مستقیم (پرداخت کامل)

                $this->purchaseAction->execute($order);

                return success([
                    'order'     => new OrderResource($order->fresh()),
                    'documents' => [
                        FinancialCodes::DOC_RECEIPT_OWNER,   // A
                        FinancialCodes::DOC_REPORT_SUPPLIER, // B
                        FinancialCodes::DOC_LOADING_ORDER    // C
                    ],
                    'message'   => 'پرداخت کامل انجام شد.'
                ]);
            }

        } catch (Exception $e) {
            return error($e->getMessage(), [], 400);
        }
    }

    /**
     * پرداخت هزینه تمدید (Hold Fee)
     */
    public function payHoldFee(PayHoldFeeRequest $request, Order $order): JsonResponse
    {
        $this->authorize('pay', $order);
        // منطق تمدید (کد قبلی شما)
        return success(new OrderResource($order), 'تمدید شد');
    }

    /**
     * آپلود فیش بانکی
     */
    public function uploadReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);
        return success(new OrderResource($order), 'آپلود شد');
    }
}
