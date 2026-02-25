<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinalizeOrderInvoiceRequest;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderActionController extends Controller
{
    /**
     * ثبت فاکتور نهایی و "هولد" کردن سهم مشاور فنی
     */
    public function finalizeInvoice(FinalizeOrderInvoiceRequest $request, Order $order): JsonResponse
    {
        // 1. چک کردن دسترسی کاربر (تغییر پیشنهادی Cursor)
        $this->authorize('finalizeInvoice', $order);

        $validated = $request->validated();

        // 2. بررسی وضعیت سفارش (باید پردازش شده باشد)
        if ($order->{Order::STATUS} !== Order::STATUS_PROCESSING) {
            return error('سفارش در وضعیت مناسب برای ثبت فاکتور نهایی نیست.', [], 400);
        }

        // 3. پیدا کردن مبلغ سهم و کاربر مشاور
        $feeAmount = null;
        $consultantUserId = null;

        if ($order->{Order::ENGINEER_FEE_AMOUNT} > 0) {
            $feeAmount = $order->{Order::ENGINEER_FEE_AMOUNT};
            // فرض: سهم مهندس به ذی‌نفع (Beneficiary) تعلق می‌گیرد
            $consultantUserId = $order->{Order::BENEFICIARY_USER_ID};
        } elseif ($order->{Order::CONTRACTOR_FEE_AMOUNT} > 0) {
            $feeAmount = $order->{Order::CONTRACTOR_FEE_AMOUNT};
            $consultantUserId = $order->{Order::BENEFICIARY_USER_ID};
        }

        DB::transaction(function () use ($order, $validated, $feeAmount, $consultantUserId) {
            // 4. آپدیت سفارش با شماره فاکتور نهایی
            $order->update([
                Order::FINAL_INVOICE_REF => $validated['final_invoice_ref'],
                // TODO: تغییر وضعیت سفارش اگر لازم است (مثلا به "فاکتور شده")
            ]);

            // 5. اگر سهمی برای مشاور وجود دارد، آن را هولد کن
            if ($feeAmount && $consultantUserId) {
                $consultant = User::find($consultantUserId);
                if (!$consultant) {
                    Log::error("User not found for consultant fee hold. Order: {$order->id}");
                    throw new \Exception("Consultant user not found.");
                }

                // رفع باگ: اگر کیف پول نداشت، یکی برایش بساز تا ارور ندهد
                $wallet = $consultant->wallet()->firstOrCreate([]);

                // 6. ایجاد تراکنش "هولد"
                $wallet->transactions()->create([
                    'order_id'    => $order->id,
                    'amount'      => $feeAmount,
                    'type'        => WalletTransaction::TYPE_FEE_HOLD,
                    'status'      => WalletTransaction::STATUS_PENDING, // در انتظار تایید نهایی
                    'description' => "سهم رزرو شده برای سفارش " . $order->{Order::ORDER_NUMBER},
                ]);

                // 7. افزایش موجودی "هولد" شده در کیف پول
                $wallet->increment('balance_hold', $feeAmount);
            }
        });

        return success([], 'فاکتور نهایی با موفقیت ثبت شد و سهم مشاور (در صورت وجود) رزرو گردید.');
    }
}
