<?php

namespace App\Http\Controllers;

use App\Events\WalletFunded;
use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * مدیریت بازگشت از درگاه پرداخت (Callback)
     */
    public function callback(Request $request): RedirectResponse
    {
        // 1. دریافت پارامترها
        $status = $request->input('Status');
        $transactionId = $request->input('transaction_id');
        $authority = $request->input('Authority');

        // 2. یافتن تراکنش
        $transaction = $this->findPendingTransaction($transactionId);

        if (! $transaction) {
            return $this->redirectClient(false, __('payment.transaction_not_found'));
        }

        // 3. بررسی وضعیت اولیه بازگشت از بانک
        if ($status !== 'OK') {
            $this->markAsFailed($transaction);
            return $this->redirectClient(false, __('payment.payment_failed'));
        }

        try {
            // 4. استعلام و تایید نهایی (Verification)
            // TODO: اینجا باید سرویس GatewayFactory صدا زده شود.
            // $gateway = GatewayFactory::make('shaparak');
            // $receipt = $gateway->verify($transaction->amount, $authority);

            // شبیه‌سازی موفقیت برای تست
            $isVerified = true;
            $refId = rand(100000, 999999); // شماره پیگیری واقعی از بانک

            if ($isVerified) {
                $this->settleWallet($transaction, $refId);

                // ارسال پیامک (اختیاری)
                // \App\Jobs\SendSmsJob::dispatch($transaction->wallet->user->mobile, ...);

                return $this->redirectClient(true, __('payment.payment_success'), $refId);
            } else {
                $this->markAsFailed($transaction);
                return $this->redirectClient(false, __('payment.verification_failed'));
            }
        } catch (\Exception $e) {
            Log::error("Payment Callback Error: " . $e->getMessage());
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

            $transaction->update([
                'status' => WalletTransaction::STATUS_CONFIRMED,
                'meta'   => [...$transaction->meta ?? [], 'ref_id'      => $refId,
                    'verified_at' => now()->toDateTimeString()]
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
        $url = $baseUrl . '/payment/result';

        $queryParams = http_build_query([
            'status'  => $success ? 'success' : 'failed',
            'message' => $message,
            'ref_id'  => $refId
        ]);

        return redirect()->to("{$url}?{$queryParams}");
    }
}
