<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Financial\ReleaseEliteYARHoldAction;
use App\Actions\Order\CancelOrderAction;
use App\Actions\Order\FinalizeInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\FinalizeOrderInvoiceRequest;
use App\Http\Requests\Order\SubmitMoadianInvoiceRequest;
use App\Models\Order;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OrderActionController extends Controller
{
    public function __construct(
        private readonly FinalizeInvoiceAction $finalizeAction,
        private readonly ReleaseEliteYARHoldAction $releaseEliteYARHold,
    ) {}

    /**
     * لغو سفارش توسط کاربر
     */
    public function cancel(Order $order, CancelOrderAction $cancelAction): JsonResponse
    {
        $this->authorize('cancel', $order);

        // خودلغویِ خریدار فعلاً هیچ وضعیتی ندارد — فاز ۴.
        //
        // تنها وضعیتِ خودلغو `pending` بود و حذف شد: سفارش تنها پس از نشستنِ پول
        // ساخته می‌شود. وضعیت‌های پس از پرداخت لِگِ تأمین‌کننده/الیت‌یار/مالیات
        // دارند و با یک refundِ ساده باز نمی‌شوند؛ مسیرشان مرجوعی/داوری است.
        //
        // پنجره‌ی لغوِ خریدار روی رزرو (بند ۳.۴) در فاز ۵ باز می‌شود و آن‌وقت
        // `$cancelAction` دوباره از اینجا صدا زده می‌شود.
        return error(__('orders.cannot_cancel'), [], 400);
    }

    /**
     * ثبت فاکتور نهایی و "هولد" کردن سهم الیت یار
     */
    public function finalizeInvoice(FinalizeOrderInvoiceRequest $request, Order $order): JsonResponse
    {
        $this->authorize('finalizeInvoice', $order);

        if ($order->status !== Order::STATUS_PROCESSING) {
            return error(__('orders.cannot_finalize_invoice'), [], 400);
        }

        try {
            $this->finalizeAction->execute($order, $request->validated('final_invoice_ref'));
        } catch (Exception $e) {
            // بدون این لاگ، شکستِ finalizeِ فاکتور (که سهم مالیِ الیت‌یار را هولد می‌کند)
            // هیچ ردِ عملیاتی نداشت و دیباگ‌ناپذیر بود.
            Log::channel('orders')->error('finalizeInvoice failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return error(__('orders.unknown_processing_error'), [], 500);
        }

        return success([], __('orders.finalize_invoice_success'));
    }

    /**
     * ثبت فاکتور مودیان الیت یار — Doc Q
     *
     * فقط توسط الیت یارِ سفارش (elite_yar_user_id — صاحب کمیسیون) قابل فراخوانی است.
     * پس از ثبت، هولد کمیسیون و مالیات الیت یار آزاد می‌شود.
     */
    public function submitEliteYARMoadianInvoice(SubmitMoadianInvoiceRequest $request, Order $order): JsonResponse
    {
        $user = Auth::user();

        // فقط الیت یارِ سفارش (کسی که کمیسیون WEY به او تعلق دارد) مجاز است.
        // این باید elite_yar_user_id باشد، نه beneficiary_user_id (گیرندهٔ کالا) —
        // هولد کمیسیون در WeighbridgeVatRouter/ReleaseEliteYARHoldAction روی
        // elite_yar_user_id ثبت/آزاد می‌شود، پس گارد مجوز هم باید با همان هم‌تراز باشد.
        if ((int) $order->{Order::ELITE_YAR_USER_ID} !== (int) $user->id) {
            return error(__('global.forbidden'), [], 403);
        }

        if ($order->{Order::ELITE_YAR_MOADIAN_REF} !== null) {
            return error(__('orders.elite_yar_moadian_already_done'), [], 409);
        }

        try {
            $this->releaseEliteYARHold->execute(
                $order,
                $request->validated('moadian_ref')
            );
        } catch (\RuntimeException $e) {
            return error($e->getMessage(), [], 422);
        } catch (Exception $e) {
            Log::channel('orders')->error('submitEliteYARMoadianInvoice failed', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return error(__('orders.unknown_processing_error'), [], 500);
        }

        return success([], __('orders.elite_yar_moadian_submitted'));
    }
}
