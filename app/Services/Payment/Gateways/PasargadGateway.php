<?php

namespace App\Services\Payment\Gateways;

use App\Models\Order;
use App\Models\User;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentReceipt;
use Illuminate\Support\Facades\Log;

/**
 * Bank Pasargad digital-account gateway.
 *
 * STATUS: scaffold — the real Pasargad REST integration is pending the bank licence
 * (per the design spec "پروسه خرید": «پس از دریافت مجوز درگاه…»). The class is fully
 * wired (factory + config) and fail-safe: until credentials are present in
 * config('payment.gateways.pasargad'), verify() returns a FAILED receipt so a callback
 * can never phantom-credit a wallet, and pay() reports that the gateway is unavailable.
 *
 * To go live, drop the two real HTTP calls into the marked spots — the surrounding
 * contract (amount matching, PaymentReceipt shape, callback settlement via
 * ProcessPaymentCallbackAction) already exists and does not change.
 */
class PasargadGateway implements PaymentGatewayInterface
{
    public function getName(): string
    {
        return 'pasargad';
    }

    /**
     * Whether the bank credentials are configured. Until then the gateway is inert.
     */
    public function isConfigured(): bool
    {
        $cfg = (array) config('payment.gateways.pasargad', []);

        return ! empty($cfg['base_url']) && ! empty($cfg['api_key']) && ! empty($cfg['terminal_id']);
    }

    /**
     * Initiate a payment: request a token from Pasargad and return the redirect target.
     *
     * @return array{transaction_id: ?string, reference_id: ?string, redirect_url: ?string}
     */
    public function pay(User $user, Order $order): array
    {
        if (! $this->isConfigured()) {
            Log::warning('PasargadGateway::pay called but credentials are not configured.', [
                'order_id' => $order->id,
            ]);

            // No token can be issued — the caller must surface an "unavailable" message.
            return ['transaction_id' => null, 'reference_id' => null, 'redirect_url' => null];
        }

        // ── INTEGRATION POINT (token request) ────────────────────────────────────
        // POST config('payment.gateways.pasargad.base_url')."/token" with terminal_id,
        // amount = (int) $order->final_amount, invoice = $order->order_number, and
        // callback = config('payment.gateways.pasargad.callback_url'); then return the
        // bank token + the hosted-payment redirect URL. Left unimplemented until the
        // licence/endpoint contract is delivered.
        throw new \RuntimeException(__('orders.errors.gateway_not_implemented'));
    }

    /**
     * Verify a returned payment with the bank (callback).
     *
     * Fails safe while unconfigured: returns a FAILED receipt so
     * ProcessPaymentCallbackAction marks the transaction failed and never credits.
     */
    public function verify(float $amount, string $authority): ?PaymentReceipt
    {
        if (! $this->isConfigured()) {
            Log::warning('PasargadGateway::verify called but credentials are not configured — failing safe.', [
                'amount' => $amount,
                'authority' => $authority,
            ]);

            return PaymentReceipt::failed();
        }

        // ── INTEGRATION POINT (verification) ─────────────────────────────────────
        // POST base_url."/verify" with { authority, amount } and translate the bank
        // response into PaymentReceipt::success($refId) or PaymentReceipt::failed().
        // MUST re-check the amount server-side against $amount before returning success.
        // Until the endpoint contract exists we fail safe rather than guess.
        return PaymentReceipt::failed();
    }
}
