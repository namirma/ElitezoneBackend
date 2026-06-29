<?php

namespace App\Actions\Financial;

use App\Models\WalletTransaction;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Attaches an uploaded receipt file to an existing pending bank-transfer
 * deposit transaction. The Action enforces:
 *  - the transaction belongs to the given user
 *  - it is a pending bank-transfer deposit (not a paid / gateway one)
 *
 * Returns the updated transaction. Throws RuntimeException on guard failure.
 */
class AttachReceiptToDepositAction
{
    public function execute(WalletTransaction $transaction, int $userId, UploadedFile $file): WalletTransaction
    {
        $this->guard($transaction, $userId);

        // Private disk — financial receipts must not be reachable via a public URL.
        $path = $file->store(WalletTransaction::PATH_RECEIPTS, 'financial');
        $originalName = $file->getClientOriginalName();

        $meta = $transaction->meta ?? [];
        $meta['receipt_path'] = $path;
        $meta['receipt_info']['image_path'] = $path;
        $meta['receipt_info']['original_name'] = $originalName;

        $transaction->update([
            'description' => $transaction->description.' | رسید آپلود شد',
            'meta' => $meta,
        ]);

        return $transaction->fresh();
    }

    private function guard(WalletTransaction $transaction, int $userId): void
    {
        if ($transaction->wallet->user_id !== $userId) {
            throw new RuntimeException(__('auth.forbidden'), 403);
        }

        $isBankTransfer = ($transaction->meta['payment_method'] ?? '') === WalletTransaction::METHOD_BANK_TRANSFER;

        if (
            $transaction->type !== WalletTransaction::TYPE_DEPOSIT ||
            $transaction->status !== WalletTransaction::STATUS_PENDING ||
            ! $isBankTransfer
        ) {
            throw new RuntimeException(__('wallet.invalid_upload_transaction'), 400);
        }
    }
}
