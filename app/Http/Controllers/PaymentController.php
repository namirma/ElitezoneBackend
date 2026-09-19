<?php

namespace App\Http\Controllers;

use App\Actions\Financial\ProcessPaymentCallbackAction;
use App\Models\WalletTransaction;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private readonly ProcessPaymentCallbackAction $callbackAction,
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
        // 1. Parse parameters (support different field names across gateways)
        $status = $request->input('Status') ?? $request->input('status');
        $transactionId = $request->input('transaction_id') ?? $request->input('clientrefid');
        $authority = $request->input('Authority') ?? $request->input('token');

        // 2. Find the pending transaction
        $transaction = WalletTransaction::where('id', $transactionId)
            ->where('status', WalletTransaction::STATUS_PENDING)
            ->first();

        if (! $transaction) {
            // لاگِ callbackِ بی‌تطبیق (جعلی/تکراری/شناسه‌ی اشتباه) — قبلاً بی‌صدا برمی‌گشت.
            Log::channel('financial')->warning('Payment callback: no pending transaction matched', [
                'transaction_id' => $transactionId,
            ]);

            return $this->redirectClient(false, __('payment.transaction_not_found'));
        }

        try {
            // 3. Verify with the gateway and settle the wallet (Always verify, ignore URL status)
            $gatewayName = $transaction->meta['gateway'] ?? 'shaparak';
            $refId = $this->callbackAction->execute($transaction, (string) $authority, $gatewayName);

            if ($refId === null) {
                return $this->redirectClient(false, __('payment.verification_failed'));
            }

            return $this->redirectClient(true, __('payment.payment_success'), $refId);

        } catch (Exception $e) {
            Log::error('Payment Callback Error: '.$e->getMessage(), [
                'transaction_id' => $transactionId,
                'authority' => $authority,
            ]);

            // DO NOT update to failed on exception (timeout/network error) so reconciliation can check it later.
            return $this->redirectClient(false, __('payment.technical_error'));
        }
    }

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    private function redirectClient(bool $success, string $message, ?string $refId = null): RedirectResponse
    {
        $baseUrl = config('app.front_url', 'http://localhost:3000');
        $url = $baseUrl.'/payment/result';

        $queryParams = http_build_query([
            'status' => $success ? self::STATUS_SUCCESS : self::STATUS_FAILED,
            'message' => $message,
            'ref_id' => $refId,
        ]);

        return redirect()->to("{$url}?{$queryParams}");
    }
}
