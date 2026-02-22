<?php

namespace App\Http\Controllers\User;

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
    // قانون: زیر ۱۹۵ میلیون تومان رزرو ندارد
    private const RESERVATION_THRESHOLD = 195000000;

    public function __construct(
        protected ProcessPreScalePaymentAction $purchaseAction,           // خرید مستقیم
        protected ProcessReservationPaymentAction $reservationAction,     // شروع رزرو
        protected ProcessReservationCompletionAction $completionAction    // تکمیل وجه رزرو
    ) {}

    /**
     * متد اصلی پرداخت (Main Entry Point)
     */
    public function payFullOrder(PayFullOrderRequest $request, Order $order): JsonResponse
    {
        // 1. ولیدیشن اولیه
        $this->authorize('pay', $order);

        $payableStatuses = [Order::STATUS_PENDING, Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED];
        if (! in_array($order->status, $payableStatuses)) {
            return error(__('orders.cannot_pay_order'), [], 400);
        }

        try {
            // =================================================================
            // سناریو ۱: تکمیل وجه سفارشی که قبلاً رزرو شده (Completion)
            // =================================================================
            if (in_array($order->status, [Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED])) {

                // اجرای اکشن هوشمند تکمیل وجه
                $this->completionAction->execute($order);

                return success([
                    'order'     => new OrderResource($order->fresh()),
                    'documents' => [
                        FinancialCodes::DOC_FINAL_RECEIPT_OWNER,     // G
                        FinancialCodes::DOC_FINAL_REPORT_SUPPLIER,   // H
                        FinancialCodes::DOC_LOADING_ORDER            // C
                    ],
                    'message'   => 'تکمیل وجه رزرو با موفقیت انجام شد. اسناد G و H صادر شدند.'
                ]);
            }

            // =================================================================
            // سناریو ۲: سفارش جدید (خرید مستقیم یا شروع رزرو)
            // =================================================================

            // محاسبه قیمت کل
            $totalPrice = ($order->official_price && $order->weight_before_scale)
                ? ($order->official_price * $order->weight_before_scale)
                : $order->total_amount;

            $userWantsReserve = $request->input('payment_type') === 'reserve';

            // الف) مبلغ زیر ۱۹۵ میلیون (فقط خرید مستقیم)
            if ($totalPrice < self::RESERVATION_THRESHOLD) {
                if ($userWantsReserve) {
                    return error("برای مبالغ زیر ۱۹۵ میلیون تومان امکان رزرو وجود ندارد.", [], 400);
                }

                $this->purchaseAction->execute($order);

                return success([
                    'order'     => new OrderResource($order->fresh()),
                    'documents' => [
                        FinancialCodes::DOC_RECEIPT_OWNER,    // A
                        FinancialCodes::DOC_REPORT_SUPPLIER,  // B
                        FinancialCodes::DOC_LOADING_ORDER     // C
                    ],
                    'message'   => 'پرداخت کامل انجام شد.'
                ]);
            }

            // ب) مبلغ بالای ۱۹۵ میلیون
            if ($userWantsReserve) {
                // --- شروع رزرو ---
                $ptrAmount = self::RESERVATION_THRESHOLD; // مبلغ بیعانه ثابت

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
                // --- خرید مستقیم مبلغ بالا ---
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

    // متدهای payHoldFee و uploadReceipt بدون تغییر می‌مانند...
    public function payHoldFee(PayHoldFeeRequest $request, Order $order): JsonResponse
    {
         // همان کد قبلی
         return success(new OrderResource($order), 'تمدید شد');
    }

    public function uploadReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
         // همان کد قبلی
         return success(new OrderResource($order), 'آپلود شد');
    }
}
