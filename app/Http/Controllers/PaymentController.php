<?php

namespace App\Http\Controllers;

use App\Events\WalletFunded;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\PaymentGatewayFactory;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayFactory $gatewayFactory
    ) {}

    public function result()
    {
        return response()->json(['message' => 'Callback URL.']);
    }

    /**
     * مدیریت بازگشت از درگاه پرداخت (Callback)
     */
    public function callback(Request $request): RedirectResponse
    {
        // 1. دریافت پارامترها (پشتیبانی از نام‌های مختلف در درگاه‌های متفاوت)
        $status = $request->input('Status') ?? $request->input('status');
        $transactionId = $request->input('transaction_id') ?? $request->input('clientrefid');
        $authority = $request->input('Authority') ?? $request->input('token');

        // 2. یافتن تراکنش
        $transaction = $this->findPendingTransaction($transactionId);

        if (! $transaction) {
            return $this->redirectClient(false, __('payment.transaction_not_found'));
        }

        // 3. بررسی وضعیت اولیه بازگشت از بانک
        if (strtolower($status) !== 'ok' && strtolower($status) !== 'success') {
            $this->markAsFailed($transaction);

            return $this->redirectClient(false, __('payment.payment_failed'));
        }

        try {
            // 4. استعلام و تایید نهایی (Verification) واقعی از طریق GatewayFactory
            $gatewayName = $transaction->meta['gateway'] ?? 'shaparak';
            $gateway = $this->gatewayFactory->make($gatewayName);

            $receipt = $gateway->verify($transaction->amount, $authority);

            // 5. بررسی موفقیت‌آمیز بودن رسید از سمت بانک
            if ($receipt && $receipt->isSuccessful()) {
                $refId = $receipt->getReferenceId();

                // شارژ کیف پول و تایید تراکنش
                $this->settleWallet($transaction, $refId);

                // ارسال پیامک (اختیاری - از کامنت خارج کنید در صورت نیاز)
                // \App\Jobs\SendSmsJob::dispatch($transaction->wallet->user->mobile, ...);

                return $this->redirectClient(true, __('payment.payment_success'), $refId);
            }

            // اگر وریفای موفق نبود
            $this->markAsFailed($transaction);

            return $this->redirectClient(false, __('payment.verification_failed'));

        } catch (Exception $e) {
            // 6. لاگ‌گیری دقیق خطاهای ارتباطی با بانک
            Log::error('Payment Callback Error: '.$e->getMessage(), [
                'transaction_id' => $transactionId,
                'authority' => $authority,
            ]);

            $this->markAsFailed($transaction);

            return $this->redirectClient(false, __('payment.technical_error'));
        }
    }

    /**
     * یافتن تراکنش در انتظار
     */
    private function findPendingTransaction($id): ?WalletTransaction
    {
        return WalletTransaction::where('id', $id)
            ->where('status', WalletTransaction::STATUS_PENDING)
            ->first();
    }

    /**
     * تغییر وضعیت تراکنش به ناموفق
     */
    private function markAsFailed(WalletTransaction $transaction): void
    {
        $transaction->update(['status' => WalletTransaction::STATUS_FAILED]);
    }

    /**
     * شارژ کیف پول و تایید تراکنش (اتمیک)
     */
    private function settleWallet(WalletTransaction $transaction, string $refId): void
    {
        DB::transaction(function () use ($transaction, $refId) {
            $wallet = Wallet::where('id', $transaction->wallet_id)->lockForUpdate()->first();

            $wallet->increment(Wallet::BALANCE, $transaction->amount);

            // استخراج و بروزرسانی تمیز فیلد JSON
            $meta = $transaction->meta ?? [];
            $meta['ref_id'] = $refId;
            $meta['verified_at'] = now()->toDateTimeString();

            $transaction->update([
                'status' => WalletTransaction::STATUS_CONFIRMED,
                'meta' => $meta,
            ]);

            WalletFunded::dispatch($transaction);
        });
    }

    /**
     * هدایت کاربر به فرانت‌اند با نتیجه عملیات
     */
    private function redirectClient(bool $success, string $message, ?string $refId = null): RedirectResponse
    {
        $baseUrl = config('app.frontend_url', 'http://localhost:3000');
        $url = $baseUrl.'/payment/result';

        $queryParams = http_build_query([
            'status' => $success ? 'success' : 'failed',
            'message' => $message,
            'ref_id' => $refId,
        ]);

        return redirect()->to("{$url}?{$queryParams}");
    }
}
