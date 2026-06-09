<?php

namespace App\Actions\Financial;

use App\Jobs\Notification\SendSmsJob;
use App\Models\PodiumDepositRecord;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\ShenasehVarizService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * اکشن پردازش اتمیک یک واریز دریافتی از پادیوم
 *
 * این اکشن idempotent است:
 *   - اگر DocNumber قبلاً ثبت شده باشد → بدون هیچ عملیاتی return می‌کند
 *   - تمام عملیات داخل DB::transaction + lockForUpdate انجام می‌شود
 *
 * فلو — دو مرحله تطبیق (Pass 1 → Pass 2 → unmatched):
 *
 *   Pass 1 — شناسه واریز (PAYA/SATNA استاندارد)
 *     ① TransferMoneyBillNumber را بررسی می‌کند (باید ۱۱ رقم عددی باشد)
 *     ② شناسه را با Algorithm 1-1 validate می‌کند
 *     ③ کد ملی را استخراج کرده کاربر را می‌یابد
 *     ④ کیف‌پول را شارژ می‌کند (matched_by='shenaseh')
 *
 *   Pass 2 — شبای فرستنده (تراکنش‌های پل یا بدون شناسه)
 *     ① شبای فرستنده را از ItemSystemMetaData.Main_Sys_Meta_Data.TrxSrcProductId استخراج می‌کند
 *     ② در UserBankAccount (status=approved) دنبال تطبیق می‌گردد
 *     ③ کیف‌پول کاربر صاحب آن حساب را شارژ می‌کند (matched_by='iban')
 *
 *   unmatched — اگر هیچ‌کدام تطبیق نداد:
 *     رکورد با note ذخیره می‌شود (ادمین باید دستی بررسی کند)
 */
class ProcessPodiumDepositAction
{
    public function __construct(
        protected ShenasehVarizService $shenasehService,
    ) {}

    /**
     * @param  array  $statement  یک ردیف Statement از Podium API
     */
    public function execute(array $statement): void
    {
        $docNumber      = (string) ($statement['DocNumber'] ?? '');
        $creditorAmount = (int) ($statement['CreditorAmount'] ?? 0);
        $depositedAt    = trim(($statement['Date'] ?? '').' '.($statement['Time'] ?? ''));

        // ── Idempotency guard ─────────────────────────────────────────────────
        if (empty($docNumber)) {
            Log::warning('ProcessPodiumDepositAction: empty DocNumber — skipped', ['statement' => $statement]);
            return;
        }

        if (PodiumDepositRecord::where(PodiumDepositRecord::DOC_NUMBER, $docNumber)->exists()) {
            return; // قبلاً پردازش شده
        }

        // ── استخراج فیلدهای مشترک ─────────────────────────────────────────
        $senderIban = $this->extractSenderIban($statement);

        // TransferMoneyBillNumber برای پل/شتاب کد بانکی است (مثل C64945308503)؛
        // شناسه واریز واقعی کاربر فقط در انتقال‌های پایا استاندارد پر می‌شود.
        $rawBillNumber = trim((string) ($statement['TransferMoneyBillNumber'] ?? ''));
        $shenasehVariz = preg_match('/^\d{11}$/', $rawBillNumber) ? $rawBillNumber : '';

        // ══════════════════════════════════════════════════════════════════════
        //  PASS 1 — شناسه واریز (پایا/ستنا)
        //
        //  شرط دوگانه: شناسه واریز معتبر + IBAN فرستنده باید در UserBankAccount
        //  با status=approved ثبت شده باشد.
        //
        //  چرا IBAN اجباری است:
        //    اگر کسی کد ملی شخص دیگری را بداند، می‌تواند شناسه واریز او را حساب
        //    کرده و از حساب خودش واریز کند. IBAN دومین لایه تأیید هویت است.
        //
        //  استثنا: اگر IBAN در metadata موجود نباشد (null)، شناسه واریز به تنهایی
        //    کافی است — این حالت در انتقال‌های قدیمی یا بدون metadata رخ می‌دهد.
        // ══════════════════════════════════════════════════════════════════════
        if (! empty($shenasehVariz) && $this->shenasehService->validate($shenasehVariz)) {
            $nationalCode = $this->shenasehService->extractNationalCode($shenasehVariz);
            $user         = User::where('national_code', $nationalCode)->first();

            if ($user) {
                // اگر IBAN فرستنده قابل استخراج است، باید با حساب ثبت‌شده تطبیق داشته باشد
                if (! empty($senderIban) && ! $this->userHasRegisteredIban($user, $senderIban)) {
                    Log::warning('ProcessPodiumDepositAction: Pass1 — IBAN فرستنده با حساب ثبت‌شده کاربر مطابقت ندارد', [
                        'user_id'       => $user->id,
                        'national_code' => $nationalCode,
                        'sender_iban'   => $senderIban,
                        'shenaseh'      => $shenasehVariz,
                        'amount'        => $creditorAmount,
                    ]);

                    PodiumDepositRecord::create([
                        PodiumDepositRecord::DOC_NUMBER          => $docNumber,
                        PodiumDepositRecord::SHENASEH_VARIZ      => $shenasehVariz,
                        PodiumDepositRecord::SENDER_IBAN         => $senderIban,
                        PodiumDepositRecord::NATIONAL_CODE       => $nationalCode,
                        PodiumDepositRecord::CREDITOR_AMOUNT     => $creditorAmount,
                        PodiumDepositRecord::DEPOSITED_AT_JALALI => $depositedAt ?: null,
                        PodiumDepositRecord::NOTE                => 'IBAN فرستنده با هیچ حساب تأییدشده‌ای برای این کاربر مطابقت ندارد',
                    ]);
                    return;
                }

                // شناسه واریز معتبر + IBAN تأیید شد (یا IBAN در دسترس نبود)
                $this->creditWallet(
                    user:          $user,
                    amount:        $creditorAmount,
                    docNumber:     $docNumber,
                    shenasehVariz: $shenasehVariz,
                    senderIban:    $senderIban,
                    nationalCode:  $nationalCode,
                    depositedAt:   $depositedAt,
                    matchedBy:     PodiumDepositRecord::MATCHED_BY_SHENASEH,
                );
                return;
            }

            // شناسه واریز معتبر ولی کاربری با این کد ملی وجود ندارد
            Log::warning('ProcessPodiumDepositAction: Pass1 — کاربری با این کد ملی یافت نشد', [
                'national_code' => $nationalCode,
                'shenaseh'      => $shenasehVariz,
                'amount'        => $creditorAmount,
            ]);

            PodiumDepositRecord::create([
                PodiumDepositRecord::DOC_NUMBER          => $docNumber,
                PodiumDepositRecord::SHENASEH_VARIZ      => $shenasehVariz,
                PodiumDepositRecord::SENDER_IBAN         => $senderIban,
                PodiumDepositRecord::NATIONAL_CODE       => $nationalCode,
                PodiumDepositRecord::CREDITOR_AMOUNT     => $creditorAmount,
                PodiumDepositRecord::DEPOSITED_AT_JALALI => $depositedAt ?: null,
                PodiumDepositRecord::NOTE                => 'کاربری با این کد ملی در سیستم یافت نشد',
            ]);
            return;
        }

        // ══════════════════════════════════════════════════════════════════════
        //  PASS 2 — شبای فرستنده ↔ UserBankAccount
        // ══════════════════════════════════════════════════════════════════════
        if (! empty($senderIban)) {
            $user = $this->findUserBySenderIban($senderIban);

            if ($user) {
                $this->creditWallet(
                    user:          $user,
                    amount:        $creditorAmount,
                    docNumber:     $docNumber,
                    shenasehVariz: $shenasehVariz ?: null,
                    senderIban:    $senderIban,
                    nationalCode:  null,
                    depositedAt:   $depositedAt,
                    matchedBy:     PodiumDepositRecord::MATCHED_BY_IBAN,
                );
                return;
            }
        }

        // ══════════════════════════════════════════════════════════════════════
        //  UNMATCHED — هیچ تطبیقی انجام نشد
        // ══════════════════════════════════════════════════════════════════════
        $note = empty($shenasehVariz) && empty($senderIban)
            ? 'شناسه واریز و شبای فرستنده هر دو خالی‌اند — نیاز به بررسی دستی'
            : (empty($shenasehVariz)
                ? 'شبای فرستنده در UserBankAccount ثبت‌شده‌ای یافت نشد'
                : 'شناسه واریز نامعتبر و شبای فرستنده ثبت نشده');

        Log::warning('ProcessPodiumDepositAction: unmatched deposit', [
            'doc_number'   => $docNumber,
            'shenaseh'     => $shenasehVariz ?: 'N/A',
            'sender_iban'  => $senderIban ?: 'N/A',
            'amount'       => $creditorAmount,
        ]);

        PodiumDepositRecord::create([
            PodiumDepositRecord::DOC_NUMBER          => $docNumber,
            PodiumDepositRecord::SHENASEH_VARIZ      => $shenasehVariz ?: null,
            PodiumDepositRecord::SENDER_IBAN         => $senderIban,
            PodiumDepositRecord::CREDITOR_AMOUNT     => $creditorAmount,
            PodiumDepositRecord::DEPOSITED_AT_JALALI => $depositedAt ?: null,
            PodiumDepositRecord::NOTE                => $note,
        ]);
    }

    // =========================================================================
    //  متدهای خصوصی
    // =========================================================================

    /**
     * شارژ کیف‌پول کاربر + ثبت WalletTransaction + PodiumDepositRecord
     * همه‌چیز داخل یک DB::transaction با lockForUpdate اجرا می‌شود.
     */
    private function creditWallet(
        User    $user,
        int     $amount,
        string  $docNumber,
        ?string $shenasehVariz,
        ?string $senderIban,
        ?string $nationalCode,
        ?string $depositedAt,
        string  $matchedBy,
    ): void {
        DB::transaction(function () use (
            $user, $amount, $docNumber, $shenasehVariz, $senderIban,
            $nationalCode, $depositedAt, $matchedBy
        ) {
            $wallet = Wallet::where(Wallet::USER_ID, $user->id)->lockForUpdate()->firstOrCreate(
                [Wallet::USER_ID => $user->id],
                [Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0],
            );

            $descKey = $matchedBy === PodiumDepositRecord::MATCHED_BY_IBAN
                ? 'wallet.podium_iban_deposit'
                : 'wallet.podium_direct_deposit';

            $descParam = $matchedBy === PodiumDepositRecord::MATCHED_BY_IBAN
                ? ['iban' => $senderIban]
                : ['shenaseh' => $shenasehVariz];

            $txn = $wallet->transactions()->create([
                WalletTransaction::AMOUNT      => $amount,
                WalletTransaction::TYPE        => WalletTransaction::TYPE_DEPOSIT,
                WalletTransaction::STATUS      => WalletTransaction::STATUS_CONFIRMED,
                WalletTransaction::DESCRIPTION => __($descKey, $descParam),
                WalletTransaction::META        => [
                    'payment_method' => WalletTransaction::METHOD_BANK_TRANSFER,
                    'source'         => 'podium_deposit',
                    'doc_number'     => $docNumber,
                    'matched_by'     => $matchedBy,
                    'shenaseh_variz' => $shenasehVariz,
                    'sender_iban'    => $senderIban,
                    'national_code'  => $nationalCode,
                ],
            ]);

            $wallet->increment(Wallet::BALANCE, $amount);

            PodiumDepositRecord::create([
                PodiumDepositRecord::DOC_NUMBER          => $docNumber,
                PodiumDepositRecord::SHENASEH_VARIZ      => $shenasehVariz,
                PodiumDepositRecord::SENDER_IBAN         => $senderIban,
                PodiumDepositRecord::NATIONAL_CODE       => $nationalCode,
                PodiumDepositRecord::CREDITOR_AMOUNT     => $amount,
                PodiumDepositRecord::USER_ID             => $user->id,
                PodiumDepositRecord::WALLET_TXN_ID       => $txn->id,
                PodiumDepositRecord::MATCHED_BY          => $matchedBy,
                PodiumDepositRecord::DEPOSITED_AT_JALALI => $depositedAt ?: null,
                PodiumDepositRecord::PROCESSED_AT        => now(),
            ]);
        });

        // SMS اطلاع‌رسانی (خارج از transaction)
        $mobile = $user->{User::MOBILE};
        if ($mobile) {
            SendSmsJob::dispatch(
                $mobile,
                __('sms.wallet_deposited_podium', [
                    'amount' => number_format((int) ($amount / 10)), // ریال → تومان
                ])
            );
        }

        Log::info('ProcessPodiumDepositAction: واریز با موفقیت پردازش شد', [
            'user_id'      => $user->id,
            'amount_rials' => $amount,
            'doc_number'   => $docNumber,
            'matched_by'   => $matchedBy,
            'sender_iban'  => $senderIban,
        ]);
    }

    /**
     * استخراج شبای فرستنده از متادیتای statement پادیوم.
     *
     * مسیر: ItemSystemMetaData (JSON string)
     *         → Main_Sys_Meta_Data.TrxSrcProductId
     *
     * برای هر دو نوع پایا و پل موجود است.
     * فرمت: IR + 24 رقم (مجموعاً 26 کاراکتر)
     */
    private function extractSenderIban(array $statement): ?string
    {
        $raw = $statement['ItemSystemMetaData'] ?? null;
        if (empty($raw)) {
            return null;
        }

        $meta = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($meta)) {
            return null;
        }

        $iban = strtoupper(trim((string) data_get($meta, 'Main_Sys_Meta_Data.TrxSrcProductId', '')));

        // اعتبارسنجی فرمت IBAN ایران: IR + 24 رقم
        if (! preg_match('/^IR\d{24}$/', $iban)) {
            return null;
        }

        return $iban;
    }

    /**
     * جستجوی کاربر بر اساس شبای فرستنده در UserBankAccount های تأییدشده.
     *
     * تنها حساب‌های با status=approved قابل match هستند.
     * شبا باید از قبل توسط Podium تأیید شده باشد (در هنگام ثبت).
     */
    private function findUserBySenderIban(string $senderIban): ?User
    {
        $normalizedIban = strtoupper(preg_replace('/\s+/', '', $senderIban));

        $bankAccount = UserBankAccount::where(function ($q) use ($normalizedIban) {
            $ibanWithoutIR = preg_replace('/^IR/i', '', $normalizedIban);
            $q->whereRaw('UPPER(REPLACE(shaba, " ", "")) = ?', [$normalizedIban])
              ->orWhereRaw('UPPER(REPLACE(shaba, " ", "")) = ?', [$ibanWithoutIR]);
        })
            ->where(UserBankAccount::STATUS, UserBankAccount::STATUS_APPROVED)
            ->with('user')
            ->first();

        return $bankAccount?->user;
    }

    /**
     * بررسی اینکه آیا یک IBAN در لیست حساب‌های تأییدشده یک کاربر وجود دارد.
     *
     * برای Pass 1 استفاده می‌شود تا اطمینان حاصل شود که IBAN فرستنده
     * با حساب ثبت‌شده همان کاربری که شناسه واریز داد مطابقت دارد.
     * این لایه دوم تأیید هویت از سوءاستفاده شناسه واریز جلوگیری می‌کند.
     */
    private function userHasRegisteredIban(User $user, string $senderIban): bool
    {
        $normalizedIban = strtoupper(preg_replace('/\s+/', '', $senderIban));
        $ibanWithoutIR  = preg_replace('/^IR/i', '', $normalizedIban);

        return $user->bankAccounts()
            ->where(UserBankAccount::STATUS, UserBankAccount::STATUS_APPROVED)
            ->where(function ($q) use ($normalizedIban, $ibanWithoutIR) {
                $q->whereRaw('UPPER(REPLACE(shaba, " ", "")) = ?', [$normalizedIban])
                  ->orWhereRaw('UPPER(REPLACE(shaba, " ", "")) = ?', [$ibanWithoutIR]);
            })
            ->exists();
    }
}
