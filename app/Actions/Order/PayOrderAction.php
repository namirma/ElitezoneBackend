<?php

namespace App\Actions\Order;

use App\Events\Order\OrderPaid;
use App\Exceptions\Order\OrderNotPayableException;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ داربستِ مسیرِ درگاه — **هیچ فراخوانی‌ای ندارد** و هیچ‌جا رجیستر نشده.
 *
 * تا وقتی مجوزِ درگاه نیامده، پرداختِ واقعی از مسیرِ کیف پول انجام می‌شود
 * (`ProcessFullOrderPaymentAction` → `ProcessPreScalePaymentAction` /
 * `ProcessReservationCompletionAction`). این کلاس عمداً نگه داشته شده تا وقتی
 * درگاه وصل شد نقطهٔ شروع داشته باشد.
 *
 * تا امروز این **تنها** جایی بود که `OrderPaid` را dispatch می‌کرد، و چون هرگز
 * اجرا نمی‌شد، رویداد در هیچ مسیرِ زنده‌ای شلیک نمی‌شد و پیامکِ پرداخت برای هیچ‌کس
 * نمی‌رفت. آن dispatch حالا در هر دو اکشنِ پرداختِ واقعی هست — پس اگر روزی این
 * مسیر وصل شد، مراقب باشید رویداد **دوبار** شلیک نشود.
 */
class PayOrderAction
{
    public function __construct(
        private readonly PaymentGatewayFactory $gatewayFactory
    ) {}

    /**
     * پردازش پرداخت سفارش از طریق گیت‌وی مشخص شده
     */
    public function execute(User $user, Order $order, string $gatewayName): Order
    {
        $gateway = $this->gatewayFactory->make($gatewayName);
        $updatedOrder = DB::transaction(function () use ($user, $order, $gateway, $gatewayName) {

            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();

            if ($lockedOrder->placer_user_id !== $user->id) {
                throw new OrderNotPayableException(__('orders.errors.not_owner'));
            }

            // منبعِ واحدِ حقیقت (شاملِ RESERVED) — هم‌تراز با مسیرِ پرداختِ کیف‌پول
            // (OrderPaymentController) تا هر دو مسیرِ پرداخت یکسان رفتار کنند و drift نکنند.
            if (! in_array($lockedOrder->status, Order::PAYABLE_STATUSES, true)) {
                throw new OrderNotPayableException(__("orders.status.{$lockedOrder->status}"));
            }

            if ($lockedOrder->payment_status === Order::PAYMENT_PAID) {
                throw new OrderNotPayableException(__('orders.errors.already_paid'));
            }

            $result = $gateway->pay($user, $lockedOrder);

            $lockedOrder->update([
                Order::PAYMENT_METHOD => $gatewayName,
                'payment_reference' => $result['reference_id'] ?? null,
            ]);

            return $lockedOrder->fresh();
        }, 3);

        OrderPaid::dispatch($updatedOrder);

        return $updatedOrder;
    }
}
