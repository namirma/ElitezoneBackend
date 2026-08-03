<?php

namespace App\Actions\Financial;

use App\Events\Financial\TransactionProcessed;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PodiumServices\PodiumWithdrawalService;
use Illuminate\Support\Carbon;
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
        //
        // ⚠️ `status` تنها گاردِ ورود نیست و نمی‌تواند باشد.
        //
        // ردیف تا **پایانِ** فاز C در وضعیت PENDING می‌ماند (عمدی: اگر تماس بانکی
        // شکست بخورد باید قابل تلاش مجدد باشد). پس شرطِ `status !== PENDING` به
        // تنهایی هیچ ورودِ هم‌زمانی را نمی‌بندد: دو تأییدِ ادمین که با هم برسند، هر
        // دو PENDING می‌بینند، هر دو رد می‌شوند و هر دو به پادیوم POST می‌کنند.
        // مسیر ادمین هم middleware idempotency ندارد و `ProcessTransactionAction`
        // این اکشن را **بیرون از** تراکنشِ خودش صدا می‌زند، پس هیچ قفلِ دیگری هم
        // در کار نیست. تنها چیزی که جلوی واریزِ دوباره را می‌گرفت `uniqueId` سمتِ
        // پادیوم بود — یعنی ایمنیِ پولِ ما به dedupِ یک سرویسِ بیرونی سپرده شده بود.
        //
        // حالا خودِ claim گارد است: تا وقتی `state = 'sending'` است و کهنه نشده،
        // ورود بسته است. TTL لازم است چون اگر پروسه بینِ فاز A و C کرش کند، بدون
        // آن ردیف برای همیشه قفل می‌ماند و هیچ ادمینی نمی‌تواند تسویه را تمام کند.
        $claimTtl = (int) config('podium.settlement.claim_ttl_minutes', 15);

        $claimed = DB::transaction(function () use ($transaction, $claimTtl) {
            $locked = WalletTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== WalletTransaction::STATUS_PENDING) {
                throw new \RuntimeException(__('admin.wallet.already_processed'));
            }

            if ($this->hasLiveClaim($locked, $claimTtl)) {
                throw new \RuntimeException(__('wallet.settlement.in_progress'));
            }

            $locked->meta = $this->withSettlement($locked, [
                'state' => 'sending',
                'claimed_at' => now()->toIso8601String(),
            ]);
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
                    // `claimed_at` پاک می‌شود تا تلاش مجدد لازم نباشد منتظر TTL بماند —
                    // شکستِ صریح یعنی هیچ تماسی دیگر در جریان نیست.
                    $locked->meta = $this->withSettlement($locked, [
                        'state' => 'failed',
                        'claimed_at' => null,
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
                'claimed_at' => null,
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
     * آیا یک تلاشِ تسویهٔ دیگر همین حالا در جریان است؟
     *
     * «در جریان» یعنی `state === 'sending'` و `claimed_at` از TTL جوان‌تر باشد.
     * یک claim بدونِ `claimed_at` (ردیف‌های قبل از این تغییر که وسطِ راه مانده‌اند)
     * کهنه فرض می‌شود — وگرنه با استقرارِ این کد قفل می‌شدند.
     */
    private function hasLiveClaim(WalletTransaction $transaction, int $ttlMinutes): bool
    {
        $settlement = ($transaction->meta ?? [])['settlement'] ?? [];

        if (($settlement['state'] ?? null) !== 'sending') {
            return false;
        }

        $claimedAt = $settlement['claimed_at'] ?? null;

        if (! is_string($claimedAt) || $claimedAt === '') {
            return false;
        }

        try {
            return Carbon::parse($claimedAt)->isAfter(now()->subMinutes($ttlMinutes));
        } catch (\Throwable) {
            // تاریخِ خراب = claimِ غیرقابل‌اعتماد؛ راه را باز بگذار تا ادمین گیر نکند.
            return false;
        }
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
