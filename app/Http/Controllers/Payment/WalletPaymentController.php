<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\PayWithWalletRequest;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Exception;

class WalletPaymentController extends Controller
{
    /**
     * پردازش پرداخت سفارش با کیف پول
     */
    public function pay(PayWithWalletRequest $request)
    {
        /** @var User $user */
        $user = Auth::user();

        // ۱. دریافت سفارش
        $order = Order::findOrFail($request->order_id);

        // ۲. اعتبارسنجی مالکیت سفارش
        if ($order->{Order::PLACER_USER_ID} !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => __('payment.error.unauthorized')
            ], Response::HTTP_FORBIDDEN);
        }

        // ۳. بررسی وضعیت پرداخت
        if ($order->payment_status === Order::PAYMENT_PAID) {
            return response()->json([
                'status' => 'error',
                'message' => __('payment.error.already_paid')
            ], Response::HTTP_BAD_REQUEST);
        }

        // ۴. دریافت کیف پول
        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json([
                'status' => 'error',
                'message' => __('payment.error.wallet_not_found')
            ], Response::HTTP_NOT_FOUND);
        }

        // ۵. بررسی موجودی
        $payableAmount = $order->{Order::FINAL_AMOUNT};
        $walletBalance = $wallet->balance;

        if ($walletBalance < $payableAmount) {
            return $this->respondInsufficientBalance($payableAmount, $walletBalance);
        }

        // ۶. انجام عملیات پرداخت
        return $this->processPaymentTransaction($wallet, $order, $payableAmount);
    }

    /**
     * پاسخ استاندارد در صورت کسری موجودی
     */
    private function respondInsufficientBalance($required, $current)
    {
        $shortage = $required - $current;

        return response()->json([
            'status' => 'error',
            'code'   => 'INSUFFICIENT_BALANCE',
            'message' => __('payment.error.insufficient_balance'),
            'data'   => [
                'required_amount' => (float) $required,
                'current_balance' => (float) $current,
                'shortage_amount' => (float) $shortage,
                'action'          => 'deposit_required'
            ]
        ], Response::HTTP_PAYMENT_REQUIRED);
    }

    /**
     * ثبت تراکنش مالی، کسر موجودی و آپدیت سفارش
     */
    private function processPaymentTransaction($wallet, $order, $amount)
    {
        return DB::transaction(function () use ($wallet, $order, $amount) {
            try {
                // الف) کسر موجودی از کیف پول
                $wallet->decrement('balance', $amount);

                // ب) ثبت رکورد در جدول wallet_transactions
                $wallet->transactions()->create([
                    WalletTransaction::ORDER_ID    => $order->id,
                    WalletTransaction::AMOUNT      => $amount,
                    WalletTransaction::TYPE        => WalletTransaction::TYPE_WITHDRAW,   // نوع: برداشت
                    WalletTransaction::STATUS      => WalletTransaction::STATUS_CONFIRMED, // وضعیت: تایید شده
                    WalletTransaction::DESCRIPTION => __('payment.transaction.description', ['order_number' => $order->order_number]),
                    WalletTransaction::META        => [
                        'action' => 'order_payment',
                        'ip' => request()->ip(),
                        'user_agent' => request()->userAgent()
                    ]
                ]);

                // ج) به‌روزرسانی وضعیت سفارش
                $order->update([
                    Order::STATUS         => Order::STATUS_PROCESSING,
                    Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
                    Order::PAYMENT_METHOD => 'wallet',
                    Order::PAID_AT        => now(),
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => __('payment.success.payment_completed'),
                    'data' => [
                        'order_id'      => $order->id,
                        'order_number'  => $order->{Order::ORDER_NUMBER},
                        'new_balance'   => $wallet->fresh()->balance,
                        'tracking_code' => $order->id
                    ]
                ], Response::HTTP_OK);

            } catch (Exception $e) {
                // Log::error('Wallet Payment Error: ' . $e->getMessage());

                return response()->json([
                    'status'  => 'error',
                    'message' => __('payment.error.transaction_failed')
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        });
    }
}
