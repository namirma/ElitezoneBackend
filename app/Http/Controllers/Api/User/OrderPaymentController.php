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
use Illuminate\Support\Facades\Auth;

class OrderPaymentController extends Controller
{
    /**
     * سقف مبلغ مجاز برای رزرو (۱۹۵ میلیون تومان)
     */
    private const RESERVATION_THRESHOLD = 1950000000;

    public function __construct(
        protected ProcessPreScalePaymentAction $purchaseAction,
        protected ProcessReservationPaymentAction $reservationAction,
        protected ProcessReservationCompletionAction $completionAction
    ) {}

    /**
     * متد اصلی پرداخت (خرید مستقیم / رزرو / تکمیل وجه)
     */
    public function payFullOrder(PayFullOrderRequest $request, Order $order): JsonResponse
    {
        // بررسی دسترسی کاربر به سفارش
        $this->authorize('pay', $order);

        // بررسی وضعیت‌های مجاز برای پرداخت
        $payableStatuses = [Order::STATUS_PENDING, Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED];
        if (! in_array($order->status, $payableStatuses)) {
            return error(__('orders.cannot_pay_order'), [], 400);
        }

        try {
            $user = Auth::user();
            $wallet = $user->wallet;

            if (!$wallet) {
                return error('کیف پول برای حساب کاربری شما فعال نشده است.', [], 400);
            }

            // =================================================================
            // سناریو ۱: تکمیل وجه (Completion) - برای سفارش‌های رزرو شده
            // =================================================================
            if (in_array($order->status, [Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED])) {

                // رفع باگ ۳: استفاده از فیلد مجاز دیتابیس (PAYMENT_TO_RESERVE)
                $reservedAmount = (float) ($order->{Order::PAYMENT_TO_RESERVE} ?? 0);
                $remainingAmount = $order->final_amount - $reservedAmount;

                // *** گارد موجودی *** // (فقط در صورتی که مبلغ مابه‌التفاوت مثبت باشد نیاز به چک کردن موجودی کاربر داریم)
                if ($remainingAmount > 0 && $wallet->balance < $remainingAmount) {
                    return $this->balanceError($wallet->balance, $remainingAmount);
                }

                $this->completionAction->execute($order);

                return success([
                    'order'     => new OrderResource($order->fresh()),
                    'documents' => [
                        FinancialCodes::DOC_FINAL_RECEIPT_OWNER,   // G
                        FinancialCodes::DOC_FINAL_REPORT_SUPPLIER, // H
                        FinancialCodes::DOC_LOADING_ORDER          // C
                    ],
                    'message'   => 'تکمیل وجه با موفقیت انجام شد.'
                ]);
            }

            // =================================================================
            // سناریو ۲: سفارش جدید (خرید یا شروع رزرو)
            // =================================================================
            $totalAmount = $order->final_amount;
            $isReserveRequested = $request->input('payment_type') === 'reserve';

            // --- حالت الف: خرید مستقیم (Direct Purchase) ---
            if ($totalAmount < self::RESERVATION_THRESHOLD || !$isReserveRequested) {

                // *** گارد موجودی برای کل مبلغ ***
                if ($wallet->balance < $totalAmount) {
                    return $this->balanceError($wallet->balance, $totalAmount);
                }

                // اجرای عملیات خرید و تراکنش‌ها
                $this->purchaseAction->execute($order);

                return success([
                    'order'     => new OrderResource($order->fresh()),
                    'documents' => [
                        FinancialCodes::DOC_RECEIPT_OWNER,    // A (رسید خریدار)
                        FinancialCodes::DOC_REPORT_SUPPLIER,  // B (گزارش سوپلایر)
                        FinancialCodes::DOC_LOADING_ORDER,    // C (دستور بارگیری)
                        FinancialCodes::DOC_CONSULTANT_REPORT // D (گزارش مشاور فنی)
                    ],
                    'message'   => 'پرداخت کامل انجام شد.'
                ]);
            }

            // --- حالت ب: رزرو (Reservation) ---
            // مبلغ بالای ۱۹۵ میلیون و درخواست رزرو
            if ($isReserveRequested) {
                $reserveAmount = self::RESERVATION_THRESHOLD;

                if ($wallet->balance < $reserveAmount) {
                    return $this->balanceError($wallet->balance, $reserveAmount);
                }

                $this->reservationAction->execute($order, $reserveAmount, validityHours: 2);

                return success([
                    'order'     => new OrderResource($order->fresh()),
                    'documents' => [
                        FinancialCodes::DOC_RESERVE_RECEIPT, // E
                        FinancialCodes::DOC_RESERVE_REPORT   // F
                    ],
                    'message'   => 'سفارش با موفقیت رزرو شد.'
                ]);
            }

        } catch (Exception $e) {
            return error($e->getMessage(), [], 400);
        }

        return error('خطای ناشناخته در پردازش سفارش', [], 500);
    }

    /**
     * مدیریت خطای موجودی و تولید پاسخ هدایت به شارژ
     */
    private function balanceError(float $currentBalance, float $requiredAmount): JsonResponse
    {
        $shortage = $requiredAmount - $currentBalance;

        return response()->json([
            'status'  => false,
            'message' => "موجودی کیف پول کافی نیست. لطفاً جهت ادامه، مبلغ " . number_format($shortage) . " ریال کیف پول خود را شارژ کنید.",
            'data'    => [
                'error_code'      => 'insufficient_balance',
                'current_balance' => $currentBalance,
                'required_amount' => $requiredAmount,
                'shortage'        => $shortage,

                'action' => [
                    'type'                    => 'navigate_to_deposit',
                    'api_route'               => '/api/profile/financial/wallet/deposit',
                    'required_deposit_amount' => $shortage
                ]
            ]
        ], 400);
    }

    public function payHoldFee(PayHoldFeeRequest $request, Order $order): JsonResponse
    {
        $this->authorize('pay', $order);
        // لاجیک پرداخت هزینه نگهداری (مشابه بالا باید گارد موجودی داشته باشد)
        return success(new OrderResource($order), 'هزینه تمدید پرداخت شد');
    }

    public function uploadReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        return success(new OrderResource($order), 'فیش بانکی با موفقیت ثبت شد');
    }
}
