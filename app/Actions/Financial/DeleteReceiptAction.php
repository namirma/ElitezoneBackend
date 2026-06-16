<?php

namespace App\Actions\Financial;

use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Deletes a pending deposit receipt and cleans up the associated file.
 * Enforces:
 *  - ownership (transaction belongs to the user)
 *  - only pending transactions may be deleted (paid/approved ones are immutable)
 *
 * Throws RuntimeException with appropriate HTTP code on guard failure.
 */
class DeleteReceiptAction
{
    public function execute(WalletTransaction $transaction, int $userId): void
    {
        if ($transaction->wallet->user_id !== $userId) {
            throw new RuntimeException(__('auth.forbidden'), 403);
        }

        if ($transaction->status !== WalletTransaction::STATUS_PENDING) {
            throw new RuntimeException(__('wallet.receipt_delete_forbidden'), 400);
        }

        $path = $transaction->meta['receipt_path'] ?? null;
        if ($path && Storage::disk('financial')->exists($path)) {
            Storage::disk('financial')->delete($path);
        }

        $transaction->delete();
    }
}
