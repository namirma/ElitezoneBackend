<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Events\Financial\WalletFunded;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Support\Facades\DB;

class ProcessPaymentCallbackAction
{
    public function __construct(
        private readonly PaymentGatewayFactory $gatewayFactory,
    ) {}

    /**
     * Verify the payment with the gateway and settle the wallet on success.
     *
     * Returns the bank reference ID on success, or null if the gateway
     * reports the payment as unsuccessful (transaction is marked FAILED).
     *
     * Throws Exception on gateway communication errors — the caller is
     * responsible for logging and marking the transaction as failed.
     *
     * @throws \Exception on gateway communication/verification errors
     */
    public function execute(WalletTransaction $transaction, string $authority, string $gatewayName): ?string
    {
        $gateway = $this->gatewayFactory->make($gatewayName);
        $receipt = $gateway->verify($transaction->amount, $authority);

        if (! ($receipt && $receipt->isSuccessful())) {
            $transaction->update(['status' => WalletTransaction::STATUS_FAILED]);

            return null;
        }

        $refId = $receipt->getReferenceId();
        $this->settle($transaction, $refId);

        return $refId;
    }

    private function settle(WalletTransaction $transaction, string $refId): void
    {
        DB::transaction(function () use ($transaction, $refId) {
            // Re-fetch and lock the transaction row, then re-check its status under
            // the lock. Banks routinely retry callbacks; without this guard two
            // concurrent/retried callbacks could both credit the wallet (double-credit).
            $locked = WalletTransaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== WalletTransaction::STATUS_PENDING) {
                return; // already settled (or vanished) — do not credit again
            }

            $wallet = Wallet::where('id', $locked->wallet_id)->lockForUpdate()->first();
            $wallet->increment(Wallet::BALANCE, $locked->amount);

            $meta = $locked->meta ?? [];
            $meta['ref_id'] = $refId;
            $meta['verified_at'] = now()->toDateTimeString();

            $locked->update([
                'status' => WalletTransaction::STATUS_CONFIRMED,
                'meta' => $meta,
            ]);

            $user = User::find($wallet->user_id);
            WalletFunded::dispatch($user, $locked);
        });
    }
}
