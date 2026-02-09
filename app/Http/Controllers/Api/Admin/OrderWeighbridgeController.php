<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitWeighbridgeRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Exception;
use Illuminate\Http\JsonResponse;

class OrderWeighbridgeController extends Controller
{
    public function __construct(
        protected ProcessWeighbridgeResultAction $weighbridgeAction
    ) {}

    /**
     * ثبت وزن نهایی باسکول و انجام محاسبات مالی (کسری/سرک)
     *
     * @param SubmitWeighbridgeRequest $request کلاس اعتبارسنجی
     * @param Order $order مدل سفارش
     * @return JsonResponse
     */
    public function submitWeight(SubmitWeighbridgeRequest $request, Order $order): JsonResponse
    {
        // 1. دریافت داده معتبر از ریکوئست
        $validatedData = $request->validated();
        $actualWeight = (float) $validatedData['weight_after_scale'];

        try {
            // 2. اجرای اکشن (محاسبات مالی و آپدیت سفارش)
            // اکشن خروجی آرایه‌ای شامل وضعیت مالی (surplus/deficit) برمی‌گرداند
            $result = $this->weighbridgeAction->execute($order, $actualWeight);

            // 3. آماده‌سازی پیام پاسخ بر اساس نتیجه
            $message = match ($result['status']) {
                'surplus' => '.باسکول ثبت شد. سرک بار (اضافه وزن) محاسبه و از حساب مشتری کسر گردید',
                'deficit' => '.باسکول ثبت شد. کسری بار (کمبود وزن) محاسبه و به حساب مشتری عودت گردید',
                default   => '.باسکول ثبت شد. وزن باسکول با وزن اولیه برابر بود',
            };

            // 4. ارسال پاسخ موفقیت‌آمیز
            return success([
                'order'          => new OrderResource($order->fresh()),
                'weight_details' => $result, // جزئیات اختلاف وزن و مبلغ
                'message'        => $message
            ]);
        } catch (Exception $e) {
            // مدیریت خطا
            return error($e->getMessage(), [], 400);
        }
    }
}
