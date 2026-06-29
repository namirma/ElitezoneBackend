<?php

namespace App\Actions\Financial;

use App\Events\Financial\TransactionProcessed;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PodiumServices\PodiumWithdrawalService;
use Illuminate\Support\Facades\DB;

/**
 * Pays out a PENDING withdraw transaction to the user's approved bank account
 * via a Podium PAYA/SATNA settlement.
 *
 * Three-phase state machine so a DB row lock is NEVER held across the external
 * HTTP call:
 *   A. (DB tx)  lock the transaction, re-assert it is payable, claim it
 *               (settlement.state = 'sending') — the idempotency gate.
 *   B. (no lock) call Podium. uniqueId = "wd-{id}" so a retried call is deduped
 *               by Podium itself and can never double-pay.
 *   C. (DB tx)  lock again; on success → CONFIRMED + store the bank reference,
 *               on failure → settlement.state = 'failed' (stays PENDING) and rethrow
 *               so an admin can retry. The wallet was already debited at request
 *               time, so a failure here never loses or mints money.
 */
final class SettleWithdrawalAction
{
    public function __construct(private readonly PodiumWithdrawalService $podium) {}

    public function execute(WalletTransaction $transaction, ?User $admin = null, ?string $note = null): WalletTransaction
    {
        if ($transaction->type !== WalletTransaction::TYPE_WITHDRAW) {
            throw new \RuntimeException(__('wallet.settlement.not_a_withdrawal'));
        }

        // Already paid out — idempotent no-op (mirrors the double-credit guards elsewhere).
        if ($transaction->status === WalletTransaction::STATUS_CONFIRMED) {
            return $transaction;
        }

        if (! $this->podium->isConfigured()) {
            throw new \RuntimeException(__('wallet.settlement.not_configured'));
        }

        // ── Phase A: claim the transaction under a short lock ────────────────────
        $claimed = DB::transaction(function () use ($transaction) {
            $locked = WalletTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== WalletTransaction::STATUS_PENDING) {
                throw new \RuntimeException(__('admin.wallet.already_processed'));
            }

            $locked->meta = $this->withSettlement($locked, ['state' => 'sending']);
            $locked->save();

            return $locked;
        });

        // ── Phase B: call Podium OUTSIDE any lock/transaction ────────────────────
        try {
            $response = $this->podium->settle($this->buildPayload($claimed));
        } catch (\Throwable $e) {
            // Mark failed so the row is retryable; surface the error to the caller.
            DB::transaction(function () use ($claimed, $e) {
                $locked = WalletTransaction::where('id', $claimed->id)->lockForUpdate()->first();
                if ($locked) {
                    $locked->meta = $this->withSettlement($locked, [
                        'state' => 'failed',
                        'error' => mb_substr($e->getMessage(), 0, 500),
                    ]);
                    $locked->save();
                }
            });

            throw $e;
        }

        // ── Phase C: record success under a short lock ───────────────────────────
        return DB::transaction(function () use ($claimed, $response, $admin, $note) {
            $locked = WalletTransaction::where('id', $claimed->id)->lockForUpdate()->first();

            // Another worker finished first — return the settled row, don't double-write.
            if (! $locked || $locked->status === WalletTransaction::STATUS_CONFIRMED) {
                return $locked ?? $claimed;
            }

            $result = $response['result'] ?? [];

            $locked->status = WalletTransaction::STATUS_CONFIRMED;
            $locked->meta = $this->withSettlement($locked, [
                'state' => 'sent',
                'reference_number' => $response['referenceNumber'] ?? null,
                'podium_id' => data_get($result, 'id'),
                'podium_status' => data_get($result, 'status'),
                'tool_code' => data_get($result, 'toolCode'),
                'settled_by_admin_id' => $admin?->id,
                'error' => null,
            ]);

            if ($note) {
                $locked->description .= " | یادداشت ادمین: {$note}";
            }

            $locked->save();

            if ($admin) {
                activity()
                    ->performedOn($locked)
                    ->causedBy($admin)
                    ->withProperties([
                        'new_status' => WalletTransaction::STATUS_CONFIRMED,
                        'amount' => $locked->amount,
                        'reference_number' => $response['referenceNumber'] ?? null,
                    ])
                    ->log('برداشت توسط ادمین تأیید و تسویه شد.');
            }

            TransactionProcessed::dispatch($locked);

            return $locked;
        });
    }

    /**
     * Build the Podium settlement payload from the withdraw transaction's meta.
     */
    private function buildPayload(WalletTransaction $transaction): array
    {
        $meta = $transaction->meta ?? [];
        $amount = (int) $transaction->amount;

        $iban = $this->normalizeIban((string) ($meta['shaba'] ?? ''));
        if (! preg_match('/^IR\d{24}$/', $iban)) {
            throw new \RuntimeException(__('wallet.settlement.invalid_iban'));
        }

        // Tool selection: large amounts go SATNA (instant), the rest PAYA (cheap, cyclic).
        $threshold = (int) config('podium.settlement.satna_threshold', 1000000000);
        $toolCode = $amount >= $threshold ? 'SETTLEMENT_TOOL_SATNA' : 'SETTLEMENT_TOOL_PAYA';

        [$firstName, $lastName] = $this->splitName((string) ($meta['holder_name'] ?? ''));

        return [
            'amount' => $amount,
            'firstName' => $firstName !== '' ? $firstName : '-',
            'lastName' => $lastName,
            'toolCode' => $toolCode,
            'toolId' => $iban,
            // Idempotency key: Podium dedups by uniqueId, so a retried settle is safe.
            'uniqueId' => 'wd-'.$transaction->id,
            'paymentId' => 'wd-'.$transaction->id,
            'description' => __('wallet.settlement.description', ['shaba' => $iban]),
            'detailType' => config('podium.settlement.detail_type'),
        ];
    }

    /**
     * Merge new keys into the transaction's meta.settlement sub-array immutably.
     */
    private function withSettlement(WalletTransaction $transaction, array $changes): array
    {
        $meta = $transaction->meta ?? [];
        $meta['settlement'] = array_merge($meta['settlement'] ?? [], $changes);

        return $meta;
    }

    private function normalizeIban(string $raw): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $raw));

        if (preg_match('/^\d{24}$/', $iban)) {
            $iban = 'IR'.$iban;
        }

        return $iban;
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/', ' ', $full));
        if ($full === '') {
            return ['', null];
        }

        $parts = explode(' ', $full);
        $first = array_shift($parts);

        return [$first, $parts ? implode(' ', $parts) : null];
    }
}
