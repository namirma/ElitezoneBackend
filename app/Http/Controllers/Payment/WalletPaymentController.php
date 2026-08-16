<?php

namespace App\Http\Controllers\Payment;

use App\Actions\Financial\ProcessPreScalePaymentAction;
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
    public function __construct(
        protected ProcessPreScalePaymentAction $purchaseAction
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
        // `OrderPaymentController` و مسیرِ درگاه می‌خوانند. این کنترلر تنها مسیرِ
        // پرداختی بود که هنوز `[STATUS_PENDING]` را هاردکد داشت، پس سفارشِ
        // `reserved` از یک مسیر قابل تکمیل بود و از این یکی نه. فاز ۴ که
        // `pending` را حذف می‌کند، این خط دیگر لازم نیست دستکاری شود.
        if (! \in_array($order->status, Order::PAYABLE_STATUSES, true)) {
            return error(__('orders.cannot_pay_order'), [], Response::HTTP_BAD_REQUEST);
        }

        $wallet = $user->wallet;
        if (! $wallet) {
            return error(__('payment.error.wallet_not_found'), [], Response::HTTP_NOT_FOUND);
        }

        $payableAmount = (float) $order->{Order::FINAL_AMOUNT};
        if ($wallet->balance < $payableAmount) {
            $shortage = $payableAmount - $wallet->balance;

            return error(__('payment.error.insufficient_balance'), [
                'error_code' => 'INSUFFICIENT_BALANCE',
                'required_amount' => $payableAmount,
                'current_balance' => (float) $wallet->balance,
                'shortage_amount' => $shortage,
                'action' => 'deposit_required',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        try {
            $this->purchaseAction->execute($order);

            return success([
                'order' => new OrderResource($order->fresh(Order::resourceRelations())),
                'new_balance' => (float) $user->wallet->fresh()->balance,
                'tracking_code' => $order->id,
            ], __('payment.success.payment_completed'));
        } catch (InsufficientBalanceException $e) {
            // pre-check بالا با final_amount است ولی Action مبلغ قطعی POO را کسر می‌کند؛
            // اختلاف این دو نباید به 500 تبدیل شود — پاسخ ساختاریافته کمبود موجودی بده.
            return error(__('payment.error.insufficient_balance'), [
                'error_code' => 'INSUFFICIENT_BALANCE',
                'required_amount' => $e->required,
                'current_balance' => $e->available,
                'shortage_amount' => $e->required - $e->available,
                'action' => 'deposit_required',
            ], Response::HTTP_PAYMENT_REQUIRED);
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
