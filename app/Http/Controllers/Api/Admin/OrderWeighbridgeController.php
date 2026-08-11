<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\SubmitWeighbridgeRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * ⚠️ پلِ موقت — تا فاز ۷ (ثبت بارنامه توسط تأمین‌کننده).
 *
 * بند ۱۶ سند بازطراحی این کنترلر را حذف می‌کند و جایگزینش
 * `RegisterShipmentAction` است که بارنامه و باسکول را در یک اقدامِ
 * تأمین‌کننده ادغام می‌کند (بند ۳.۷). ولی آن اقدام در فاز ۷ ساخته می‌شود.
 *
 * در فاز ۰ این فایل حذف شده بود و نتیجه‌اش این بود که
 * `ProcessWeighbridgeResultAction` — تنها نویسندهٔ وضعیت `shipped` — هیچ
 * فراخوانی‌کننده‌ای نداشت. یعنی چرخهٔ عمر سفارش در `processing` بن‌بست
 * می‌خورد: نه `shipped`، نه `delivered`، پس نه نظرسنجی، نه نمودار فروش،
 * نه RFM، و نه هیچ سناریوی end-to-endِ قابلِ آزمایش. سند می‌گوید هر فاز
 * باید مستقلاً قابل مرج و تست باشد (بند ۱۲)، پس حذف باید همان‌جایی
 * بیفتد که جایگزین ساخته می‌شود، نه هفت فاز زودتر.
 *
 * **در فاز ۷ همراه با روتش حذف شود.**
 */
class OrderWeighbridgeController extends Controller
{
    public function __construct(
        protected ProcessWeighbridgeResultAction $weighbridgeAction
    ) {}

    /**
     * ثبت وزن نهایی باسکول و انجام محاسبات مالی (کسری/سرک)
     */
    public function submitWeight(SubmitWeighbridgeRequest $request, Order $order): JsonResponse
    {
        $validatedData = $request->validated();
        $actualWeight = (float) $validatedData['weight_after_scale'];

        try {
            $result = $this->weighbridgeAction->execute($order, $actualWeight);

            $diffAmount = number_format((float) $result['financial_diff']);

            $message = match ($result['status']) {
                'surplus' => __('weighbridge.messages.surplus', ['amount' => $diffAmount]),
                'deficit' => __('weighbridge.messages.deficit', ['amount' => $diffAmount]),
                default => __('weighbridge.messages.equal'),
            };

            return success([
                'order' => new OrderResource($order->fresh(Order::resourceRelations())),
                'weight_details' => $result,
                'message' => $message,
            ]);

        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // خطاهای business (پیام‌های ترجمه‌شده‌ی خود Action) — نمایش برای اپراتور مجاز است.
            return error(__('weighbridge.errors.process_failed', ['message' => $e->getMessage()]), [], 422);
        } catch (Exception $e) {
            // خطای غیرمنتظره — پیام خام exception هرگز به کلاینت برنمی‌گردد (کانونشن پروژه).
            Log::channel('financial')->error('Weighbridge processing failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return error(__('weighbridge.errors.unexpected'), [], 500);
        }
    }
}
