<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Financial\ProcessPodiumDepositAction;
use App\Events\Financial\WalletFunded;
use App\Http\Controllers\Controller;
use App\Models\PodiumDepositRecord;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\PodiumServices\DepositStatementService;
use Hekmatinasser\Verta\Verta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * پنل ادمین — مدیریت واریزهای مستقیم بانکی (شناسه واریز / پادیوم)
 *
 * سه endpoint:
 *   GET  /admin/financial/podium-deposits              → لیست کامل سوابق + فیلتر
 *   POST /admin/financial/podium-deposits/poll         → اجرای دستی polling (بدون انتظار cron)
 *   POST /admin/financial/podium-deposits/{record}/credit → شارژ دستی ادمین برای واریز unmatched
 */
class AdminPodiumDepositController extends Controller
{
    public function __construct(
        protected DepositStatementService $statementService,
        protected ProcessPodiumDepositAction $processAction,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /admin/financial/podium-deposits
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * لیست سوابق واریز مستقیم بانکی
     *
     * Query params:
     *   status  = matched | unmatched | all  (پیش‌فرض: all)
     *   from    = تاریخ میلادی شروع (Y-m-d)
     *   to      = تاریخ میلادی پایان (Y-m-d)
     *   search  = جستجو روی کد ملی یا شناسه واریز
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'all');

        $records = PodiumDepositRecord::query()
            ->with([
                'user:id,mobile,national_code',
                'user.profile:user_id,name',
                'walletTransaction:id,amount,status,created_at',
            ])
            // فیلتر وضعیت
            ->when($status === 'matched', fn ($q) => $q->whereNotNull(PodiumDepositRecord::PROCESSED_AT))
            ->when($status === 'unmatched', fn ($q) => $q->whereNull(PodiumDepositRecord::PROCESSED_AT))
            // فیلتر تاریخ
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to')))
            // جستجو
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where(function ($q2) use ($term) {
                    $q2->where(PodiumDepositRecord::NATIONAL_CODE, 'like', "%{$term}%")
                        ->orWhere(PodiumDepositRecord::SHENASEH_VARIZ, 'like', "%{$term}%")
                        ->orWhere(PodiumDepositRecord::DOC_NUMBER, 'like', "%{$term}%")
                        ->orWhere(PodiumDepositRecord::SENDER_IBAN, 'like', "%{$term}%")
                        ->orWhere(PodiumDepositRecord::SENDER_NAME, 'like', "%{$term}%");
                });
            })
            ->latest()
            ->paginate($this->perPage());

        // آمار کلی (بدون فیلتر تاریخ)
        $stats = [
            'total' => PodiumDepositRecord::count(),
            'matched' => PodiumDepositRecord::whereNotNull(PodiumDepositRecord::PROCESSED_AT)->count(),
            'unmatched' => PodiumDepositRecord::whereNull(PodiumDepositRecord::PROCESSED_AT)->count(),
        ];

        return success([
            'stats' => $stats,
            'records' => $records,
        ], 'سوابق واریز مستقیم بانکی دریافت شد');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/financial/podium-deposits/poll
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * اجرای دستی polling صورت‌حساب پادیوم
     *
     * ادمین می‌تواند بدون انتظار برای cron هر ۵ دقیقه، polling را
     * به صورت دستی اجرا کند و نتیجه فوری دریافت کند.
     *
     * Body (اختیاری):
     *   minutes  = پنجره زمانی به دقیقه (پیش‌فرض: 60، حداکثر: 10080 = ۷ روز)
     *
     * ادمین می‌تواند مثلاً minutes=1440 بدهد تا واریزهای کل روز را بررسی کند.
     *
     * این همان ابزاری است که وقتی کاربری می‌گوید «واریز کردم و شارژ نشدم» سراغش
     * می‌روند — و تا پیش از این خودش بریده بود: فقط ۵۰ ردیفِ اولِ بازه خوانده
     * می‌شد و اگر آن ۵۰ ردیف برداشت بودند، پاسخ «۰ واریز» بود. حالا
     * `getIncomingDeposits` کلِ بازه را صفحه‌به‌صفحه می‌خواند.
     */
    public function poll(Request $request): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 60);
        $minutes = max(1, min($minutes, 10080)); // بین ۱ دقیقه تا ۷ روز

        $to = Carbon::now();
        $from = $to->copy()->subMinutes($minutes);

        // ساعتِ بانک محلی است — مثل PollPodiumDepositsJob. بدون این تبدیل، پنجره
        // ۳٫۵ ساعت عقب می‌رفت: ادمینی که «یک ساعتِ گذشته» می‌خواست، بازه‌ای را
        // می‌گرفت که ۳٫۵ ساعت پیش تمام شده بود.
        $tz = (string) config('podium.deposit.statement.timezone', 'Asia/Tehran');
        $fromLocal = $from->copy()->setTimezone($tz);
        $toLocal = $to->copy()->setTimezone($tz);

        $fromJalali = Verta::instance($fromLocal)->format('Y/m/d');
        $fromTime = $fromLocal->format('H:i:s');
        $toJalali = Verta::instance($toLocal)->format('Y/m/d');
        $toTime = $toLocal->format('H:i:s');

        // دریافت صورت‌حساب از پادیوم
        try {
            $deposits = $this->statementService->getIncomingDeposits(
                fromDate: $fromJalali,
                toDate: $toJalali,
                fromTime: $fromTime,
                toTime: $toTime,
            );
        } catch (\Throwable $e) {
            return error(__('admin.podium.connection_error'), [], 503);
        }

        $totalIncoming = count($deposits);
        $newMatched = 0;
        $newUnmatched = 0;
        $skipped = 0;
        $errors = [];

        foreach ($deposits as $statement) {
            $docNumber = (string) ($statement['DocNumber'] ?? '');

            // از قبل پردازش شده؟
            if (! empty($docNumber) && PodiumDepositRecord::where(PodiumDepositRecord::DOC_NUMBER, $docNumber)->exists()) {
                $skipped++;

                continue;
            }

            try {
                $this->processAction->execute($statement);

                // چک کن آیا matched شد یا unmatched
                $matched = PodiumDepositRecord::where(PodiumDepositRecord::DOC_NUMBER, $docNumber)
                    ->whereNotNull(PodiumDepositRecord::PROCESSED_AT)
                    ->exists();

                $matched ? $newMatched++ : $newUnmatched++;
            } catch (\Throwable $e) {
                // جزئیات کامل فقط در لاگ — پیام خام exception به کلاینت برنمی‌گردد.
                Log::channel('financial')->error('Podium deposit statement processing failed', [
                    'doc_number' => $docNumber ?: '-',
                    'error' => $e->getMessage(),
                ]);

                $errors[] = [
                    'doc_number' => $docNumber ?: '-',
                    'error' => __('admin.podium.statement_process_failed'),
                ];
            }
        }

        return success([
            'window' => [
                'from' => "{$fromJalali} {$fromTime}",
                'to' => "{$toJalali} {$toTime}",
                'minutes' => $minutes,
            ],
            'summary' => [
                'total_incoming' => $totalIncoming,
                'new_matched' => $newMatched,
                'new_unmatched' => $newUnmatched,
                'skipped' => $skipped,
                'errors' => count($errors),
            ],
            'errors' => $errors,
        ], "polling اجرا شد — {$newMatched} تراکنش جدید شارژ کیف‌پول شد");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/financial/podium-deposits/{record}/credit
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * شارژ دستی کیف‌پول برای یک واریز unmatched
     *
     * زمانی استفاده می‌شود که واریز در سیستم ثبت شده ولی هیچ کاربری
     * به صورت خودکار تطبیق داده نشده — ادمین پس از بررسی دستی، کاربر
     * درست را مشخص می‌کند و این endpoint کیف‌پول را شارژ می‌کند.
     *
     * Body:
     *   user_id  (int, required) — کاربری که باید کیف‌پولش شارژ شود
     *   note     (string, optional) — توضیح برای ادمین‌های بعدی
     *
     * ایمنی:
     *   - اگر واریز قبلاً matched شده باشد → 422 (idempotency)
     *   - کل عملیات داخل DB::transaction + lockForUpdate
     *   - matched_by = 'admin' برای قابلیت پیگیری
     */
    public function credit(Request $request, PodiumDepositRecord $record): JsonResponse
    {
        // ── Guard اولیه: واریز قبلاً پردازش شده (fail fast، بدون قفل) ─────────
        if ($record->{PodiumDepositRecord::PROCESSED_AT} !== null) {
            return error(__('admin.podium_already_credited'), [], 422);
        }

        // ── اعتبارسنجی ورودی ────────────────────────────────────────────────
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $adminNote = $validated['note'] ?? null;

        // ── شارژ کیف‌پول (transaction + lock) ──────────────────────────────
        // WalletFunded باید بعد از commit dispatch شود، پس $txn را از transaction بیرون می‌آوریم.
        $txn = DB::transaction(function () use ($record, $user, $adminNote) {
            // Re-fetch + re-check رکورد زیر قفل ردیف. گارد بالا روی مدل قفل‌نشده و
            // بیرون از تراکنش اجرا می‌شود؛ روت‌های ادمین idempotency middleware
            // ندارند، پس دابل‌کلیک یا دو ادمین همزمان می‌توانستند هر دو از آن گارد
            // عبور کنند و کیف‌پول دو بار شارژ شود (همان الگوی ProcessTransactionAction).
            $lockedRecord = PodiumDepositRecord::whereKey($record->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedRecord || $lockedRecord->{PodiumDepositRecord::PROCESSED_AT} !== null) {
                return null; // قبلاً توسط درخواست موازی شارژ شده — هرگز دوباره credit نکن
            }

            $amount = (int) $lockedRecord->{PodiumDepositRecord::CREDITOR_AMOUNT};

            $wallet = Wallet::where(Wallet::USER_ID, $user->id)
                ->lockForUpdate()
                ->firstOrCreate(
                    [Wallet::USER_ID => $user->id],
                    [Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0],
                );

            $description = __('wallet.podium_admin_credit', [
                'amount' => number_format((int) ($amount / 10)),
            ]);

            $walletTxn = $wallet->transactions()->create([
                WalletTransaction::AMOUNT => $amount,
                WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
                WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
                WalletTransaction::DESCRIPTION => $description,
                WalletTransaction::META => [
                    'payment_method' => WalletTransaction::METHOD_BANK_TRANSFER,
                    'source' => 'podium_admin_credit',
                    'doc_number' => $lockedRecord->{PodiumDepositRecord::DOC_NUMBER},
                    'matched_by' => PodiumDepositRecord::MATCHED_BY_ADMIN,
                    'credited_by_admin_id' => Auth::id(),
                    'admin_note' => $adminNote,
                ],
            ]);

            $wallet->increment(Wallet::BALANCE, $amount);

            $lockedRecord->update([
                PodiumDepositRecord::USER_ID => $user->id,
                PodiumDepositRecord::WALLET_TXN_ID => $walletTxn->id,
                PodiumDepositRecord::MATCHED_BY => PodiumDepositRecord::MATCHED_BY_ADMIN,
                PodiumDepositRecord::PROCESSED_AT => now(),
                PodiumDepositRecord::NOTE => $adminNote ?? 'شارژ دستی توسط ادمین',
            ]);

            return $walletTxn;
        });

        // درخواست موازی زودتر شارژ کرده — idempotent، بدون credit دوم.
        if ($txn === null) {
            return error(__('admin.podium_already_credited'), [], 422);
        }

        WalletFunded::dispatch($user, $txn);

        $amount = (int) $txn->{WalletTransaction::AMOUNT};

        return success([
            'user_id' => $user->id,
            'amount_rials' => $amount,
            'amount_tomans' => (int) ($amount / 10),
            'doc_number' => $record->{PodiumDepositRecord::DOC_NUMBER},
        ], __('admin.podium_credit_success'));
    }
}
