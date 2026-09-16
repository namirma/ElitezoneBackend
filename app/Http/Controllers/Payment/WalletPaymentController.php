<?php

namespace App\Http\Controllers\Payment;

use App\Actions\Order\ProcessFullOrderPaymentAction;
use App\Exceptions\BusinessException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\PayWithWalletRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WalletPaymentController extends Controller
{
    /**
     * ⚠️ همان اکشنِ `OrderPaymentController::payFullOrder` — نه `ProcessPreScalePaymentAction`.
     *
     * ‏`PAYABLE_STATUSES` فقط `reserved` است: سفارشِ خریدِ مستقیم در
     * `CheckoutController::pay` با وضعیتِ `processing` ساخته و همان‌جا تسویه
     * می‌شود و هرگز به این کنترلر نمی‌رسد. پس تنها سفارشی که از این مسیر عبور
     * می‌کند یک رزروِ باز است. این کنترلر قبلاً آن را به
     * `ProcessPreScalePaymentAction` می‌داد که فقط `payment_status` را می‌سنجید:
     * کلِ POO دوباره از خریدار کسر می‌شد، سفارش `paid` می‌شد و بیعانه (PTR) برای
     * همیشه در `balance_hold` کیف سازه می‌ماند — چون انقضا و لغو فقط سفارشِ
     * `reserved` را برمی‌گردانند. گاردِ انقضای رزرو هم دور زده می‌شد.
     */
    public function __construct(
        protected ProcessFullOrderPaymentAction $payFullOrderAction
    ) {}

    public function pay(PayWithWalletRequest $request)
    {
        $user = Auth::user();
        // larastan نتیجه‌ی `findOrFail` را `Model|Collection` می‌بیند.
        /** @var Order $order */
        $order = Order::findOrFail($request->order_id);

        // پرداخت‌کننده و نه ثبت‌کننده (فاز ۶): کسی مجاز به پرداخت است که پول از
        // کیف پولِ او برداشته می‌شود. برای سفارش‌های معمولی این دو یکی هستند.
        if ($order->payerUserId() !== $user->id) {
            return error(__('payment.error.unauthorized'), [], Response::HTTP_FORBIDDEN);
        }

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return error(__('payment.error.already_paid'), [], Response::HTTP_BAD_REQUEST);
        }

        // `PAYABLE_STATUSES` و نه یک فهرستِ دستی — همان قاعده‌ای که
        // `OrderPaymentController` و مسیرِ درگاه می‌خوانند.
        if (! \in_array($order->status, Order::PAYABLE_STATUSES, true)) {
            return error(__('orders.cannot_pay_order'), [], Response::HTTP_BAD_REQUEST);
        }

        $wallet = $user->wallet;
        if (! $wallet) {
            return error(__('payment.error.wallet_not_found'), [], Response::HTTP_NOT_FOUND);
        }

        // پیش‌چکِ `balance < final_amount` عمداً حذف شد: برای تکمیلِ رزرو مبلغِ
        // لازم «POO − PTR − تخفیف» است، نه `final_amount`، پس آن چک خریداری را
        // که بیعانه را داده و باقی را دارد رد می‌کرد. گاردِ واقعی زیرِ قفل در
        // `ProcessReservationCompletionAction` است و همان پاسخِ ۴۰۲ پایین را می‌سازد.
        try {
            $this->payFullOrderAction->execute($order, false, $wallet);

            return success([
                'order' => new OrderResource($order->fresh(Order::resourceRelations())),
                'new_balance' => (float) $user->wallet->fresh()->balance,
                'tracking_code' => $order->id,
            ], __('payment.success.payment_completed'));
        } catch (InsufficientBalanceException $e) {
            return error(__('payment.error.insufficient_balance'), [
                'error_code' => 'INSUFFICIENT_BALANCE',
                'required_amount' => $e->required,
                'current_balance' => $e->available,
                'shortage_amount' => $e->required - $e->available,
                'action' => 'deposit_required',
            ], Response::HTTP_PAYMENT_REQUIRED);
        } catch (BusinessException $e) {
            // رزروِ منقضی (`orders.reserve_expired_cannot_complete`) — نباید پشتِ ۵۰۰ِ
            // عمومی پنهان شود. `error()` با وضعیتِ لفظی و نه `$e->render()`: Scramble
            // خروجیِ `render()` را نمی‌بیند و کلِ مستندِ پاسخ‌های این متد را خراب می‌کند.
            return error($e->getMessage(), [], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Exception $e) {
            Log::error('Wallet payment failed', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return error(__('orders.payment_processing_error'), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
