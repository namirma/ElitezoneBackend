<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Financial\ProcessPodiumDepositAction;
use App\Http\Controllers\Controller;
use App\Models\PodiumDepositRecord;
use App\Services\PodiumServices\DepositStatementService;
use Hekmatinasser\Verta\Verta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * پنل ادمین — مدیریت واریزهای مستقیم بانکی (شناسه واریز / پادیوم)
 *
 * دو endpoint:
 *   GET  /admin/financial/podium-deposits        → لیست کامل سوابق + فیلتر
 *   POST /admin/financial/podium-deposits/poll   → اجرای دستی polling (بدون انتظار cron)
 */
class AdminPodiumDepositController extends Controller
{
    public function __construct(
        protected DepositStatementService  $statementService,
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
                'user:id,mobile,name,national_code',
                'walletTransaction:id,amount,status,created_at',
            ])
            // فیلتر وضعیت
            ->when($status === 'matched',   fn ($q) => $q->whereNotNull(PodiumDepositRecord::PROCESSED_AT))
            ->when($status === 'unmatched', fn ($q) => $q->whereNull(PodiumDepositRecord::PROCESSED_AT))
            // فیلتر تاریخ
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'),   fn ($q) => $q->whereDate('created_at', '<=', $request->input('to')))
            // جستجو
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where(function ($q2) use ($term) {
                    $q2->where(PodiumDepositRecord::NATIONAL_CODE,  'like', "%{$term}%")
                        ->orWhere(PodiumDepositRecord::SHENASEH_VARIZ, 'like', "%{$term}%")
                        ->orWhere(PodiumDepositRecord::DOC_NUMBER,     'like', "%{$term}%");
                });
            })
            ->latest()
            ->paginate($this->perPage());

        // آمار کلی (بدون فیلتر تاریخ)
        $stats = [
            'total'     => PodiumDepositRecord::count(),
            'matched'   => PodiumDepositRecord::whereNotNull(PodiumDepositRecord::PROCESSED_AT)->count(),
            'unmatched' => PodiumDepositRecord::whereNull(PodiumDepositRecord::PROCESSED_AT)->count(),
        ];

        return success([
            'stats'   => $stats,
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
     *   minutes  = پنجره زمانی به دقیقه (پیش‌فرض: 60، حداکثر: 1440 = یک روز)
     *
     * ادمین می‌تواند مثلاً minutes=1440 بدهد تا واریزهای کل روز را بررسی کند.
     */
    public function poll(Request $request): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 60);
        $minutes = max(1, min($minutes, 1440)); // بین ۱ دقیقه تا ۲۴ ساعت

        $to   = Carbon::now();
        $from = $to->copy()->subMinutes($minutes);

        // تبدیل به شمسی برای API پادیوم
        $fromJalali = Verta::instance($from)->format('Y/m/d');
        $fromTime   = $from->format('H:i:s');
        $toJalali   = Verta::instance($to)->format('Y/m/d');
        $toTime     = $to->format('H:i:s');

        // دریافت صورت‌حساب از پادیوم
        try {
            $deposits = $this->statementService->getIncomingDeposits(
                fromDate: $fromJalali,
                toDate:   $toJalali,
                fromTime: $fromTime,
                toTime:   $toTime,
            );
        } catch (\Throwable $e) {
            return error('خطا در اتصال به پادیوم: '.$e->getMessage(), [], 503);
        }

        $totalIncoming = count($deposits);
        $newMatched    = 0;
        $newUnmatched  = 0;
        $skipped       = 0;
        $errors        = [];

        foreach ($deposits as $statement) {
            $docNumber = (string) ($statement['DocNumber'] ?? '');

            // از قبل پردازش شده؟
            if (! empty($docNumber) && PodiumDepositRecord::where(PodiumDepositRecord::DOC_NUMBER, $docNumber)->exists()) {
                $skipped++;

                continue;
            }

            $countBefore = PodiumDepositRecord::whereNotNull(PodiumDepositRecord::PROCESSED_AT)->count();

            try {
                $this->processAction->execute($statement);

                // چک کن آیا matched شد یا unmatched
                $matched = PodiumDepositRecord::where(PodiumDepositRecord::DOC_NUMBER, $docNumber)
                    ->whereNotNull(PodiumDepositRecord::PROCESSED_AT)
                    ->exists();

                $matched ? $newMatched++ : $newUnmatched++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'doc_number' => $docNumber ?: '-',
                    'error'      => $e->getMessage(),
                ];
            }
        }

        return success([
            'window' => [
                'from'    => "{$fromJalali} {$fromTime}",
                'to'      => "{$toJalali} {$toTime}",
                'minutes' => $minutes,
            ],
            'summary' => [
                'total_incoming' => $totalIncoming,     // کل واریزهای ورودی از پادیوم
                'new_matched'    => $newMatched,         // واریزهای جدید + کیف‌پول شارژ شد
                'new_unmatched'  => $newUnmatched,       // واریز بدون شناسه معتبر / کاربر
                'skipped'        => $skipped,            // قبلاً پردازش شده بود
                'errors'         => count($errors),
            ],
            'errors' => $errors,
        ], "polling اجرا شد — {$newMatched} تراکنش جدید شارژ کیف‌پول شد");
    }
}
