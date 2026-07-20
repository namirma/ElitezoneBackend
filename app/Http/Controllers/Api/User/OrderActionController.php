<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Financial\ReleaseEliteYARHoldAction;
use App\Actions\Order\CancelOrderAction;
use App\Actions\Order\FinalizeInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\FinalizeOrderInvoiceRequest;
use App\Http\Requests\Order\SubmitMoadianInvoiceRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

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

        // A buyer may only self-cancel an order that has NOT been paid yet.
        // - PENDING orders carry no money, so cancellation is always safe.
        // - RESERVED / RESERVED_EXTENDED orders cannot be cancelled before expiry
        //   (CancelOrderAction enforces this); the system auto-cancels them on expiry.
        // - PROCESSING and later states are paid/settled and must NOT be unwound by a
        //   simple refund (supplier/eliteYAR/VAT legs are involved). Those go through
        //   the dedicated return/dispute flow (مرجوعی/داوری), never self-cancel.
        if ($order->status !== Order::STATUS_PENDING) {
            return error(__('orders.cannot_cancel'), [], 400);
        }

        $cancelAction->execute($order, 'لغو توسط کاربر');

        return success(new OrderResource($order->fresh(Order::resourceRelations())), __('orders.cancelled_successfully'));
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
            return error(__('orders.unknown_processing_error'), [], 500);
        }

        return success([], __('orders.elite_yar_moadian_submitted'));
    }
}
