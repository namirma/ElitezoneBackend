<?php

namespace App\Actions\Financial;

use App\Jobs\Notification\SendSmsJob;
use App\Models\PodiumDepositRecord;
use App\Models\User;
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
 * فلو:
 *   ① DocNumber را بررسی می‌کند (idempotency guard)
 *   ② شناسه واریز را validate می‌کند (Algorithm 1-1)
 *   ③ کد ملی را استخراج کرده، کاربر را پیدا می‌کند
 *   ④ در یک DB::transaction:
 *      - wallet را lockForUpdate می‌کند
 *      - WalletTransaction با STATUS_CONFIRMED ایجاد می‌کند
 *      - موجودی wallet را increment می‌کند
 *      - PodiumDepositRecord را ذخیره می‌کند
 *   ⑤ SMS اطلاع‌رسانی ارسال می‌کند
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

        // TransferMoneyBillNumber برای پل/شتاب کد بانکی است (مثل C64945308503)؛
        // شناسه واریز واقعی کاربر فقط در انتقال‌های پایا استاندارد پر می‌شود.
        // پس اگر رشته فقط عددی و ۱۱ رقم باشد → شناسه واریز کاربر؛ وگرنه خالی.
        $rawBillNumber = trim((string) ($statement['TransferMoneyBillNumber'] ?? ''));
        $shenasehVariz = (preg_match('/^\d{11}$/', $rawBillNumber)) ? $rawBillNumber : '';

        // ── ① Idempotency guard ──────────────────────────────────────────────
        if (empty($docNumber)) {
            Log::warning('ProcessPodiumDepositAction: empty DocNumber — skipped', ['statement' => $statement]);

            return;
        }

        if (PodiumDepositRecord::where(PodiumDepositRecord::DOC_NUMBER, $docNumber)->exists()) {
            return; // قبلاً پردازش شده
        }

        // ── ② اعتبارسنجی شناسه واریز ─────────────────────────────────────
        if (empty($shenasehVariz) || ! $this->shenasehService->validate($shenasehVariz)) {
            Log::warning('ProcessPodiumDepositAction: invalid/missing شناسه واریز', [
                'doc_number'    => $docNumber,
                'shenaseh'      => $shenasehVariz,
                'amount'        => $creditorAmount,
            ]);

            PodiumDepositRecord::create([
                PodiumDepositRecord::DOC_NUMBER          => $docNumber,
                PodiumDepositRecord::SHENASEH_VARIZ      => $shenasehVariz ?: null,
                PodiumDepositRecord::CREDITOR_AMOUNT     => $creditorAmount,
                PodiumDepositRecord::DEPOSITED_AT_JALALI => $depositedAt ?: null,
                PodiumDepositRecord::NOTE                => 'شناسه واریز نامعتبر یا خالی',
            ]);

            return;
        }

        // ── ③ پیدا کردن کاربر ───────────────────────────────────────────────
        $nationalCode = $this->shenasehService->extractNationalCode($shenasehVariz);
        $user         = User::where('national_code', $nationalCode)->first();

        if (! $user) {
            Log::warning('ProcessPodiumDepositAction: کاربری با این کد ملی یافت نشد', [
                'national_code' => $nationalCode,
                'shenaseh'      => $shenasehVariz,
                'amount'        => $creditorAmount,
                'doc_number'    => $docNumber,
            ]);

            PodiumDepositRecord::create([
                PodiumDepositRecord::DOC_NUMBER          => $docNumber,
                PodiumDepositRecord::SHENASEH_VARIZ      => $shenasehVariz,
                PodiumDepositRecord::NATIONAL_CODE       => $nationalCode,
                PodiumDepositRecord::CREDITOR_AMOUNT     => $creditorAmount,
                PodiumDepositRecord::DEPOSITED_AT_JALALI => $depositedAt ?: null,
                PodiumDepositRecord::NOTE                => 'کاربری با این کد ملی در سیستم یافت نشد',
            ]);

            return;
        }

        // ── ④ DB::transaction — کریدیت کیف‌پول ─────────────────────────────
        DB::transaction(function () use ($user, $creditorAmount, $docNumber, $shenasehVariz, $nationalCode, $depositedAt) {

            // قفل کیف‌پول برای جلوگیری از Race Condition
            $wallet = Wallet::where(Wallet::USER_ID, $user->id)->lockForUpdate()->firstOrCreate([
                Wallet::USER_ID => $user->id,
            ], [
                Wallet::BALANCE      => 0,
                Wallet::BALANCE_HOLD => 0,
            ]);

            // ایجاد WalletTransaction با وضعیت CONFIRMED مستقیم
            // (پول از بانک تأیید شده — نیازی به تأیید ادمین نیست)
            $txn = $wallet->transactions()->create([
                WalletTransaction::AMOUNT      => $creditorAmount,
                WalletTransaction::TYPE        => WalletTransaction::TYPE_DEPOSIT,
                WalletTransaction::STATUS      => WalletTransaction::STATUS_CONFIRMED,
                WalletTransaction::DESCRIPTION => __('wallet.podium_direct_deposit', ['shenaseh' => $shenasehVariz]),
                WalletTransaction::META        => [
                    'payment_method' => WalletTransaction::METHOD_BANK_TRANSFER,
                    'source'         => 'podium_deposit',
                    'doc_number'     => $docNumber,
                    'shenaseh_variz' => $shenasehVariz,
                    'national_code'  => $nationalCode,
                ],
            ]);

            // اضافه کردن مستقیم به موجودی (مثل ProcessTransactionAction)
            $wallet->increment(Wallet::BALANCE, $creditorAmount);

            // ثبت رکورد idempotency
            PodiumDepositRecord::create([
                PodiumDepositRecord::DOC_NUMBER          => $docNumber,
                PodiumDepositRecord::SHENASEH_VARIZ      => $shenasehVariz,
                PodiumDepositRecord::NATIONAL_CODE       => $nationalCode,
                PodiumDepositRecord::CREDITOR_AMOUNT     => $creditorAmount,
                PodiumDepositRecord::USER_ID             => $user->id,
                PodiumDepositRecord::WALLET_TXN_ID       => $txn->id,
                PodiumDepositRecord::DEPOSITED_AT_JALALI => $depositedAt ?: null,
                PodiumDepositRecord::PROCESSED_AT        => now(),
            ]);
        });

        // ── ⑤ SMS اطلاع‌رسانی (خارج از transaction) ────────────────────────
        $mobile = $user->{User::MOBILE};
        if ($mobile) {
            SendSmsJob::dispatch(
                $mobile,
                __('sms.wallet_deposited_podium', [
                    'amount' => number_format((int) ($creditorAmount / 10)), // ریال → تومان
                ])
            );
        }

        Log::info('ProcessPodiumDepositAction: واریز پادیوم با موفقیت پردازش شد', [
            'user_id'       => $user->id,
            'national_code' => $nationalCode,
            'amount_rials'  => $creditorAmount,
            'doc_number'    => $docNumber,
        ]);
    }
}
