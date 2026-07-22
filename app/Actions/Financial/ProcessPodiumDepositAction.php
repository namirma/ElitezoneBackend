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
 * قانون اصلی: شناسه واریز در همه حالات الزامی است.
 * هیچ واریزی بدون شناسه واریز معتبر به صورت خودکار شارژ نمی‌شود.
 *
 * فلو — Pass 1 → unmatched:
 *
 *   Pass 1 — شناسه واریز (PAYA/SATNA استاندارد)
 *     ① TransferMoneyBillNumber را بررسی می‌کند (باید ۱۱ رقم عددی باشد)
 *     ② شناسه را validate می‌کند (الگوریتم کد ملی + رقم کنترل)
 *     ③ کد ملی را استخراج کرده کاربر را می‌یابد
 *     ④ شبای فرستنده را بررسی می‌کند (دومین عامل تأیید هویت):
 *        - اگر شبا موجود باشد باید در UserBankAccount (status=approved) آن کاربر باشد
 *        - اگر شبا در metadata نباشد، شناسه واریز به تنهایی کافی است
 *     ⑤ کیف‌پول را شارژ می‌کند (matched_by='shenaseh')
 *
 *   انتقال‌های پل (Pol/Bridge) — بدون شناسه واریز → unmatched
 *     انتقال‌های پل TransferMoneyBillNumber را با کد مرجع بانکی (مثل C6494530F774)
 *     پر می‌کنند، نه شناسه واریز کاربر. چون شناسه واریز الزامی است، این واریزها
 *     خودکار شارژ نمی‌شوند. شبای فرستنده برای تسهیل بررسی ادمین شناسایی می‌شود.
 *
 *   unmatched — هر واریز بدون شناسه واریز معتبر:
 *     رکورد با note و شبای شناسایی‌شده ذخیره می‌شود (ادمین باید دستی بررسی کند)
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
        $docNumber = (string) ($statement['DocNumber'] ?? '');
        $creditorAmount = (int) ($statement['CreditorAmount'] ?? 0);

        // فرمت زمان پادیوم: "HH:MM.SS.microseconds" — نرمال‌سازی به "HH:MM:SS"
        $rawTime = trim((string) ($statement['Time'] ?? ''));
        $rawTime = preg_replace('/^(\d{1,2}:\d{2})\.(\d{2})\.\d+$/', '$1:$2', $rawTime);
        $depositedAt = trim(($statement['Date'] ?? '').' '.$rawTime);

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
        $senderName = $this->extractSenderName($statement);

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

            // یک کد ملی می‌تواند چند حسابِ بانکیِ ثبت‌شده داشته باشد؛ پس وقتی IBANِ فرستنده
            // موجود است، ابتدا کاربر را از روی همان IBAN (شبا سراسراً یکتا) پیدا می‌کنیم تا
            // در حالتِ اشتراکِ کد ملی، واریز قطعاً به صاحبِ همان حساب بنشیند. سپس تطابقِ
            // کد ملیِ صاحبِ حساب با کد ملیِ شناسهٔ واریز به‌عنوان لایهٔ امنیتی بررسی می‌شود.
            // در نبودِ IBAN، به جستجوی کد ملی برمی‌گردیم (رفتار قبلی).
            $user = ! empty($senderIban)
                ? $this->resolveDepositUserByIban($nationalCode, $senderIban)
                : User::where('national_code', $nationalCode)->first();

            if ($user) {
                // اگر IBAN فرستنده قابل استخراج است، باید با حساب ثبت‌شده تطبیق داشته باشد
                if (! empty($senderIban) && ! $this->userHasRegisteredIban($user, $senderIban)) {
                    Log::warning('ProcessPodiumDepositAction: Pass1 — IBAN فرستنده با حساب ثبت‌شده کاربر مطابقت ندارد', [
                        'user_id' => $user->id,
                        'national_code' => $nationalCode,
                        'sender_iban' => $senderIban,
                        'shenaseh' => $shenasehVariz,
                        'amount' => $creditorAmount,
                    ]);

                    PodiumDepositRecord::create([
                        PodiumDepositRecord::DOC_NUMBER => $docNumber,
                        PodiumDepositRecord::SHENASEH_VARIZ => $shenasehVariz,
                        PodiumDepositRecord::SENDER_IBAN => $senderIban,
                        PodiumDepositRecord::SENDER_NAME => $senderName,
                        PodiumDepositRecord::NATIONAL_CODE => $nationalCode,
                        PodiumDepositRecord::CREDITOR_AMOUNT => $creditorAmount,
                        PodiumDepositRecord::DEPOSITED_AT_JALALI => $depositedAt ?: null,
                        PodiumDepositRecord::NOTE => 'IBAN فرستنده با هیچ حساب تأییدشده‌ای برای این کاربر مطابقت ندارد',
                    ]);

                    return;
                }

                // شناسه واریز معتبر + IBAN تأیید شد (یا IBAN در دسترس نبود)
                $this->creditWallet(
                    user: $user,
                    amount: $creditorAmount,
                    docNumber: $docNumber,
                    shenasehVariz: $shenasehVariz,
                    senderIban: $senderIban,
                    senderName: $senderName,
                    nationalCode: $nationalCode,
                    depositedAt: $depositedAt,
                    matchedBy: PodiumDepositRecord::MATCHED_BY_SHENASEH,
                );

                return;
            }

            // شناسه واریز معتبر ولی کاربری با این کد ملی وجود ندارد
            Log::warning('ProcessPodiumDepositAction: Pass1 — کاربری با این کد ملی یافت نشد', [
                'national_code' => $nationalCode,
                'shenaseh' => $shenasehVariz,
                'amount' => $creditorAmount,
            ]);

            PodiumDepositRecord::create([
                PodiumDepositRecord::DOC_NUMBER => $docNumber,
                PodiumDepositRecord::SHENASEH_VARIZ => $shenasehVariz,
                PodiumDepositRecord::SENDER_IBAN => $senderIban,
                PodiumDepositRecord::SENDER_NAME => $senderName,
                PodiumDepositRecord::NATIONAL_CODE => $nationalCode,
                PodiumDepositRecord::CREDITOR_AMOUNT => $creditorAmount,
                PodiumDepositRecord::DEPOSITED_AT_JALALI => $depositedAt ?: null,
                PodiumDepositRecord::NOTE => 'کاربری با این کد ملی در سیستم یافت نشد',
            ]);

            return;
        }

        // ══════════════════════════════════════════════════════════════════════
        //  PASS 2 (اطلاعاتی) — شناسایی شبای فرستنده برای تسهیل کار ادمین
        //
        //  ⚠ شناسه واریز در همه حالات الزامی است.
        //  انتقال‌های پل (Pol) در TransferMoneyBillNumber کد مرجع بانکی دارند
        //  (مثل C6494530F774)، نه شناسه واریز کاربر. بنابراین این انتقال‌ها
        //  به صورت خودکار شارژ نمی‌شوند — ادمین باید دستی تأیید کند.
        //
        //  شبای فرستنده را فقط برای نمایش در پنل ادمین شناسایی می‌کنیم.
        // ══════════════════════════════════════════════════════════════════════
        $suggestedUser = ! empty($senderIban) ? $this->findUserBySenderIban($senderIban) : null;

        // ══════════════════════════════════════════════════════════════════════
        //  UNMATCHED — شناسه واریز موجود نیست یا معتبر نیست
        // ══════════════════════════════════════════════════════════════════════
        if (! empty($senderIban) && $suggestedUser !== null) {
            // پل + کاربر شناخته‌شده: نیاز به تأیید ادمین دارد ولی حیاتی نیست
            $note = "انتقال پل — شبا {$senderIban} متعلق به کاربر #{$suggestedUser->id} شناسایی شد اما شناسه واریز ندارد. شارژ فقط با تأیید ادمین انجام می‌شود";

            Log::info('ProcessPodiumDepositAction: پل بدون شناسه واریز → unmatched (ادمین باید تأیید کند)', [
                'doc_number' => $docNumber,
                'sender_iban' => $senderIban,
                'suggested_user' => $suggestedUser->id,
                'amount' => $creditorAmount,
            ]);
        } else {
            // بدون IBAN یا IBAN ناشناخته: نیاز به بررسی جدی‌تر دارد
            $note = ! empty($senderIban)
                ? "انتقال پل — شناسه واریز ندارد و شبا {$senderIban} در سیستم ثبت نشده. بررسی دستی لازم است"
                : (empty($shenasehVariz)
                    ? 'شناسه واریز و شبای فرستنده هر دو خالی‌اند — نیاز به بررسی دستی'
                    : 'شناسه واریز نامعتبر — نیاز به بررسی دستی');

            Log::warning('ProcessPodiumDepositAction: unmatched deposit', [
                'doc_number' => $docNumber,
                'shenaseh' => $shenasehVariz ?: 'N/A',
                'sender_iban' => $senderIban ?: 'N/A',
                'amount' => $creditorAmount,
            ]);
        }

        PodiumDepositRecord::create([
            PodiumDepositRecord::DOC_NUMBER => $docNumber,
            PodiumDepositRecord::SHENASEH_VARIZ => $shenasehVariz ?: null,
            PodiumDepositRecord::SENDER_IBAN => $senderIban ?: null,
            PodiumDepositRecord::SENDER_NAME => $senderName,
            PodiumDepositRecord::NATIONAL_CODE => $suggestedUser?->national_code,
            PodiumDepositRecord::CREDITOR_AMOUNT => $creditorAmount,
            PodiumDepositRecord::DEPOSITED_AT_JALALI => $depositedAt ?: null,
            PodiumDepositRecord::NOTE => $note,
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
        User $user,
        int $amount,
        string $docNumber,
        ?string $shenasehVariz,
        ?string $senderIban,
        ?string $senderName,
        ?string $nationalCode,
        ?string $depositedAt,
        string $matchedBy,
    ): void {
        // بارگذاری پروفایل تأییدشده کاربر (ثبت‌شده از پاسارگاد)
        $profile = $user->profile;

        DB::transaction(function () use (
            $user, $profile, $amount, $docNumber, $shenasehVariz, $senderIban,
            $senderName, $nationalCode, $depositedAt, $matchedBy
        ) {
            $wallet = Wallet::firstOrCreate(
                [Wallet::USER_ID => $user->id],
                [Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0],
            );
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            $descKey = $matchedBy === PodiumDepositRecord::MATCHED_BY_IBAN
                ? 'wallet.podium_iban_deposit'
                : 'wallet.podium_direct_deposit';

            $descParam = $matchedBy === PodiumDepositRecord::MATCHED_BY_IBAN
                ? ['iban' => $senderIban]
                : ['shenaseh' => $shenasehVariz];

            $txn = $wallet->transactions()->create([
                WalletTransaction::AMOUNT => $amount,
                WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
                WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
                WalletTransaction::DESCRIPTION => __($descKey, $descParam),
                WalletTransaction::META => [
                    'payment_method' => WalletTransaction::METHOD_BANK_TRANSFER,
                    'source' => 'podium_deposit',
                    'doc_number' => $docNumber,
                    'matched_by' => $matchedBy,
                    'shenaseh_variz' => $shenasehVariz,
                    'sender_iban' => $senderIban,
                    'national_code' => $nationalCode,
                    // اطلاعات تأییدشده فرستنده از پروفایل (برای فاکتور/رسید)
                    'depositor' => [
                        'name' => $profile?->name,
                        'family' => $profile?->family,
                        'full_name' => $user->full_name,
                        'national_code' => $profile?->national_code ?? $nationalCode,
                        'national_id' => $profile?->national_id,
                        'mobile' => $user->{User::MOBILE},
                    ],
                ],
            ]);

            $wallet->increment(Wallet::BALANCE, $amount);

            PodiumDepositRecord::create([
                PodiumDepositRecord::DOC_NUMBER => $docNumber,
                PodiumDepositRecord::SHENASEH_VARIZ => $shenasehVariz,
                PodiumDepositRecord::SENDER_IBAN => $senderIban,
                PodiumDepositRecord::SENDER_NAME => $senderName,
                PodiumDepositRecord::NATIONAL_CODE => $nationalCode,
                PodiumDepositRecord::CREDITOR_AMOUNT => $amount,
                PodiumDepositRecord::USER_ID => $user->id,
                PodiumDepositRecord::WALLET_TXN_ID => $txn->id,
                PodiumDepositRecord::MATCHED_BY => $matchedBy,
                PodiumDepositRecord::DEPOSITED_AT_JALALI => $depositedAt ?: null,
                PodiumDepositRecord::PROCESSED_AT => now(),
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
            'user_id' => $user->id,
            'amount_rials' => $amount,
            'doc_number' => $docNumber,
            'matched_by' => $matchedBy,
            'sender_iban' => $senderIban,
        ]);
    }

    /**
     * استخراج شبای فرستنده از statement پادیوم.
     *
     * اولویت‌بندی مسیرها (مستندات رسمی پادیوم):
     *   ۱. PayaResult.SourceIban    ← پایا (مستند رسمی)
     *   ۲. SatnaResult.SourceIban   ← ساتنا (مستند رسمی)
     *   ۳. ItemSystemMetaData.Main_Sys_Meta_Data.TrxSrcProductId  ← پل/Bridge (غیررسمی)
     *
     * فرمت IBAN ایران: IR + 24 رقم (مجموعاً 26 کاراکتر)
     */
    private function extractSenderIban(array $statement): ?string
    {
        // ── مسیر ۱: PayaResult.SourceIban (مستند رسمی پادیوم — پایا) ─────────
        $payaIban = strtoupper(trim((string) data_get($statement, 'PayaResult.SourceIban', '')));
        if (preg_match('/^IR\d{24}$/', $payaIban)) {
            return $payaIban;
        }

        // ── مسیر ۲: SatnaResult.SourceIban (مستند رسمی پادیوم — ساتنا) ───────
        $satnaIban = strtoupper(trim((string) data_get($statement, 'SatnaResult.SourceIban', '')));
        if (preg_match('/^IR\d{24}$/', $satnaIban)) {
            return $satnaIban;
        }

        // ── مسیر ۳: ItemSystemMetaData (فیلد غیررسمی — پل/Bridge) ──────────
        $raw = $statement['ItemSystemMetaData'] ?? null;
        if (empty($raw)) {
            return null;
        }

        $meta = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($meta)) {
            return null;
        }

        $iban = strtoupper(trim((string) data_get($meta, 'Main_Sys_Meta_Data.TrxSrcProductId', '')));

        if (! preg_match('/^IR\d{24}$/', $iban)) {
            return null;
        }

        return $iban;
    }

    /**
     * استخراج نام فرستنده از statement پادیوم.
     *
     * اولویت‌بندی مسیرها (مستندات رسمی پادیوم):
     *   ۱. SatnaResult.SourceName + SourceLastName  ← ساتنا (مستند رسمی)
     *   ۲. ItemSystemMetaData.Main_Sys_Meta_Data.TrxSrcProductOwnerName / OwnerName  ← پل (غیررسمی)
     *   ۳. Description regex (فال‌بک متنی)
     *
     * توجه: PayaResult نام فرستنده ندارد — فقط SourceIban.
     */
    private function extractSenderName(array $statement): ?string
    {
        // ── مسیر ۱: SatnaResult.SourceName + SourceLastName (مستند رسمی — ساتنا) ─
        $satnaFirst = trim((string) data_get($statement, 'SatnaResult.SourceName', ''));
        $satnaLast = trim((string) data_get($statement, 'SatnaResult.SourceLastName', ''));
        if ($satnaFirst !== '' || $satnaLast !== '') {
            $name = trim("{$satnaFirst} {$satnaLast}");
            if (! empty($name) && strtolower($name) !== 'null') {
                return $name;
            }
        }

        // ── مسیر ۲: ItemSystemMetaData (فیلد غیررسمی — پل/Bridge) ──────────
        $raw = $statement['ItemSystemMetaData'] ?? null;
        if (! empty($raw)) {
            $meta = is_string($raw) ? json_decode($raw, true) : $raw;
            if (is_array($meta)) {
                $name = data_get($meta, 'Main_Sys_Meta_Data.TrxSrcProductOwnerName')
                     ?? data_get($meta, 'Main_Sys_Meta_Data.OwnerName');

                $name = trim((string) ($name ?? ''));

                if (! empty($name) && strtolower($name) !== 'null') {
                    return $name;
                }
            }
        }

        // ── مسیر ۳: فیلد Description (فال‌بک متنی) ─────────────────────────
        // بانک پاسارگاد در Description اسم فرستنده را می‌نویسد:
        //   پایا → "توسط نوید میرترابی مهابادی"
        //   پل   → "به درخواست نوید میرترابی مهابادی"
        $description = trim((string) ($statement['Description'] ?? ''));
        if (! empty($description)) {
            if (preg_match('/توسط\s+(.+?)(?:\s+با\s+|\s+از\s+|$)/u', $description, $m)) {
                $candidate = trim($m[1]);
                if (! empty($candidate) && strtolower($candidate) !== 'null') {
                    return $candidate;
                }
            }
            if (preg_match('/به\s+درخواست\s+(.+?)(?:\s+با\s+|\s+از\s+|$)/u', $description, $m)) {
                $candidate = trim($m[1]);
                if (! empty($candidate)) {
                    return $candidate;
                }
            }

            Log::debug('ProcessPodiumDepositAction: sender name not found — full statement keys', [
                'statement_keys' => array_keys($statement),
                'description' => mb_substr($description, 0, 200),
            ]);
        }

        return null;
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
    /**
     * کاربر را از روی IBANِ فرستنده (شبا سراسراً یکتا در user_bank_accounts) پیدا می‌کند
     * و فقط زمانی برمی‌گرداند که کد ملیِ صاحبِ حساب با کد ملیِ شناسهٔ واریز یکی باشد
     * (رفعِ ابهامِ چند-حسابیِ یک کد ملی + حفظِ لایهٔ امنیتیِ تطابقِ هویت).
     */
    private function resolveDepositUserByIban(?string $nationalCode, string $senderIban): ?User
    {
        $normalizedIban = strtoupper(preg_replace('/\s+/', '', $senderIban));
        $ibanWithoutIR = preg_replace('/^IR/i', '', $normalizedIban);

        $account = UserBankAccount::query()
            ->where(UserBankAccount::STATUS, UserBankAccount::STATUS_APPROVED)
            ->where(function ($q) use ($normalizedIban, $ibanWithoutIR) {
                $q->whereRaw('UPPER(REPLACE(shaba, " ", "")) = ?', [$normalizedIban])
                    ->orWhereRaw('UPPER(REPLACE(shaba, " ", "")) = ?', [$ibanWithoutIR]);
            })
            ->with('user')
            ->first();

        $user = $account?->user;

        if ($user !== null && (string) $user->national_code === (string) $nationalCode) {
            return $user;
        }

        return null;
    }

    private function userHasRegisteredIban(User $user, string $senderIban): bool
    {
        $normalizedIban = strtoupper(preg_replace('/\s+/', '', $senderIban));
        $ibanWithoutIR = preg_replace('/^IR/i', '', $normalizedIban);

        return $user->bankAccounts()
            ->where(UserBankAccount::STATUS, UserBankAccount::STATUS_APPROVED)
            ->where(function ($q) use ($normalizedIban, $ibanWithoutIR) {
                $q->whereRaw('UPPER(REPLACE(shaba, " ", "")) = ?', [$normalizedIban])
                    ->orWhereRaw('UPPER(REPLACE(shaba, " ", "")) = ?', [$ibanWithoutIR]);
            })
            ->exists();
    }
}
