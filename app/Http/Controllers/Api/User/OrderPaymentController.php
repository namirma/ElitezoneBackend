<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Order\ProcessFullOrderPaymentAction;
use App\Exceptions\BusinessException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\PayFullOrderRequest;
use App\Http\Requests\Order\UploadOrderReceiptRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Support\Money;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OrderPaymentController extends Controller
{
    public function __construct(
        protected ProcessFullOrderPaymentAction $payFullOrderAction,
    ) {}

    public function payFullOrder(PayFullOrderRequest $request, Order $order): JsonResponse
    {
        $this->authorize('pay', $order);

        // منبعِ واحدِ حقیقت — هم‌تراز با مسیرِ درگاه (PayOrderAction).
        if (! in_array($order->status, Order::PAYABLE_STATUSES, true)) {
            return error(__('orders.cannot_pay_order'), [], 400);
        }

        $wallet = Auth::user()->wallet;
        if (! $wallet) {
            return error(__('wallet.wallet_not_activated'), [], 400);
        }

        try {
            $result = $this->payFullOrderAction->execute(
                $order,
                $request->validated('payment_type') === 'reserve',
                $wallet,
            );

            return success([
                'order' => new OrderResource($order->fresh(Order::resourceRelations())),
                'documents' => $result->documents,
                'message' => __($result->messageKey),
            ]);
        } catch (InsufficientBalanceException $e) {
            return $this->balanceError($e->available, $e->required);
        } catch (BusinessException $e) {
            // رزروِ منقضی (`orders.reserve_expired_cannot_complete`) — قبلاً catch پایین
            // آن را به ۵۰۰ و «خطا در پردازش پرداخت» تبدیل می‌کرد. `error()` با وضعیتِ
            // لفظی و نه `$e->render()`، تا Scramble پاسخ‌های این متد را ببیند.
            return error($e->getMessage(), [], 422);
        } catch (Exception $e) {
            Log::error('Order full payment failed', [
                'order_id' => $order->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return error(__('orders.payment_processing_error'), [], 500);
        }
    }

    public function uploadReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorize('pay', $order);

        // Private disk — buyer order receipts must not be reachable via a public URL.
        $path = $request->file('receipt')->store('order_receipts', 'financial');

        $order->update([Order::PAYMENT_RECEIPT_REF => $path]);

        return success(
            new OrderResource($order->fresh(Order::resourceRelations())),
            __('orders.receipt_uploaded_success'),
        );
    }

    private function balanceError(float $currentBalance, float $requiredAmount): JsonResponse
    {
        $shortage = $requiredAmount - $currentBalance;

        return error(
            __('wallet.insufficient_balance_with_shortage', ['shortage' => Money::format($shortage)]),
            [
                'error_code' => 'insufficient_balance',
                'current_balance' => $currentBalance,
                'required_amount' => $requiredAmount,
                'shortage' => $shortage,
                'action' => [
                    'type' => 'navigate_to_deposit',
                    'api_route' => route('wallet.deposit', [], false),
                    'required_deposit_amount' => $shortage,
                ],
            ],
            400
        );
    }
}
