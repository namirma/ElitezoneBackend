<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Financial\ReleaseConsultantHoldAction;
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
        private readonly ReleaseConsultantHoldAction $releaseConsultantHold,
    ) {}

    /**
     * لغو سفارش توسط کاربر
     */
    public function cancel(Order $order, CancelOrderAction $cancelAction): JsonResponse
    {
        $this->authorize('cancel', $order);

        $cancelableStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_RESERVED_EXTENDED,
            Order::STATUS_PROCESSING,
            Order::STATUS_PREPARING,
            Order::STATUS_WEIGHBRIDGE_DONE,
            Order::STATUS_WAYBILL_ISSUED,
        ];

        if (! in_array($order->status, $cancelableStatuses)) {
            return error(__('orders.cannot_cancel'), [], 400);
        }

        $cancelAction->execute($order, 'لغو توسط کاربر');

        return success(new OrderResource($order->fresh(Order::resourceRelations())), __('orders.cancelled_successfully'));
    }

    /**
     * ثبت فاکتور نهایی و "هولد" کردن سهم مشاور
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
     * ثبت فاکتور مودیان مشاور فنی — Doc Q
     *
     * فقط توسط مشاور فنی سفارش (beneficiary) قابل فراخوانی است.
     * پس از ثبت، هولد کمیسیون و مالیات مشاور آزاد می‌شود.
     */
    public function submitConsultantMoadianInvoice(SubmitMoadianInvoiceRequest $request, Order $order): JsonResponse
    {
        $user = Auth::user();

        // فقط مشاور این سفارش مجاز است
        if ((int) $order->{Order::BENEFICIARY_USER_ID} !== (int) $user->id) {
            return error(__('global.forbidden'), [], 403);
        }

        if ($order->{Order::CONSULTANT_MOADIAN_REF} !== null) {
            return error(__('orders.consultant_moadian_already_done'), [], 409);
        }

        try {
            $this->releaseConsultantHold->execute(
                $order,
                $request->validated('moadian_ref')
            );
        } catch (\RuntimeException $e) {
            return error($e->getMessage(), [], 422);
        } catch (Exception $e) {
            return error(__('orders.unknown_processing_error'), [], 500);
        }

        return success([], __('orders.consultant_moadian_submitted'));
    }
}
