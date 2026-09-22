<?php

namespace App\Jobs\Financial;

use App\Actions\Financial\ProcessPodiumDepositAction;
use App\Models\Setting;
use App\Services\PodiumServices\DepositStatementService;
use Hekmatinasser\Verta\Verta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Job polling صورت‌حساب سپرده پادیوم
 *
 * هر ۵ دقیقه اجرا می‌شود.
 *
 * پنجره زمانی: از **watermarkِ آخرین polling موفق** (منهای overlap) تا لحظه‌ی حال.
 * پنجره‌ی ثابتِ «۱۵ دقیقه‌ی گذشته» یعنی هر بازه‌ای که در آن worker نمی‌دوید برای
 * همیشه از دست می‌رفت؛ با watermark، اجرای بعدی همان بازه را جبران می‌کند.
 *
 * ایمنی:
 * - withoutOverlapping() در Kernel → اجرای موازی ندارد
 * - ProcessPodiumDepositAction هر DocNumber را فقط یک‌بار پردازش می‌کند (idempotent،
 *   با ایندکسِ یکتا روی podium_deposit_records.doc_number) — پس overlap و جبران بی‌خطرند
 * - خطای هر statement به صورت isolated log می‌شود — statement‌های بعدی پردازش می‌شوند
 * - watermark فقط با پردازشِ بی‌خطای کلِ بازه جلو می‌رود
 */
class PollPodiumDepositsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** کلیدِ watermark در جدول `settings`. */
    private const WATERMARK_KEY = 'podium.deposits.polled_up_to';

    /** جابِ singleton — جلوگیری از اجرای هم‌زمانِ دو poll (backlog/cadence). */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'poll-podium-deposits';
    }

    /** تعداد دفعات retry در صورت خطا */
    public int $tries = 3;

    /** تأخیر retry */
    public int $backoff = 60;

    public function handle(
        DepositStatementService $statementService,
        ProcessPodiumDepositAction $processAction,
    ): void {
        /*
         * گاردِ محیط — لایه‌ی دوم. زمان‌بندی در `routes/console.php` بیرون از
         * production اصلاً ثبت نمی‌شود، ولی یک dispatchِ دستی (tinker، تستِ کسی،
         * یک jobِ باقی‌مانده در صف) همان صورت‌حسابِ **واقعیِ** بانکی را می‌خواند و
         * کیف پولِ همان محیط را شارژ می‌کند. پس این‌جا هم می‌ایستد.
         *
         * `warning` و نه `info`: اگر این خط در لاگ دیده شود یعنی کسی در محیطی که
         * پولینگ خاموش است آن را صدا زده — رویدادی که باید دیده شود، نه نویزِ
         * هر پنج دقیقه (در حالتِ خاموش، زمان‌بند هیچ‌وقت صدایش نمی‌زند).
         */
        if (! config('podium.deposit.polling_enabled')) {
            Log::channel('financial')->warning('PollPodiumDepositsJob رد شد: پولینگِ واریز در این محیط خاموش است.', [
                'env' => app()->environment(),
                'hint' => 'PODIUM_DEPOSIT_POLLING_ENABLED',
            ]);

            return;
        }

        $to = Carbon::now();
        $from = $this->windowStart($to);

        // ساعتِ بانک محلی است، نه UTC. `app.timezone` روی UTC است، پس تبدیلِ مستقیمِ
        // یک لحظه‌ی UTC به «تاریخ شمسی + ساعت» پنجره را ۳٫۵ ساعت عقب می‌برد: هر واریز
        // در نهایت دیده می‌شد، ولی با ۳٫۵ ساعت تأخیر در شارژِ کیف پول.
        $tz = (string) config('podium.deposit.statement.timezone', 'Asia/Tehran');
        $fromLocal = $from->copy()->setTimezone($tz);
        $toLocal = $to->copy()->setTimezone($tz);

        $fromJalali = Verta::instance($fromLocal)->format('Y/m/d');
        $fromTime = $fromLocal->format('H:i:s');
        $toJalali = Verta::instance($toLocal)->format('Y/m/d');
        $toTime = $toLocal->format('H:i:s');

        Log::info('PollPodiumDepositsJob: شروع polling', [
            'from' => "{$fromJalali} {$fromTime}",
            'to' => "{$toJalali} {$toTime}",
            'window_minutes' => $from->diffInMinutes($to),
        ]);

        try {
            $deposits = $statementService->getIncomingDeposits(
                fromDate: $fromJalali,
                toDate: $toJalali,
                fromTime: $fromTime,
                toTime: $toTime,
            );
        } catch (\Throwable $e) {
            Log::error('PollPodiumDepositsJob: خطا در دریافت صورت‌حساب از پادیوم', [
                'error' => $e->getMessage(),
            ]);
            // throw می‌کنیم (نه fail) تا tries/backoff واقعاً اجرا شود؛ fail() جاب را فوراً
            // شکست‌خورده علامت می‌زد و retryها را دور می‌زد.
            throw $e;
        }

        $total = count($deposits);
        $processed = 0;
        $errors = 0;

        foreach ($deposits as $statement) {
            try {
                $processAction->execute($statement);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error('PollPodiumDepositsJob: خطا در پردازش statement', [
                    'error' => $e->getMessage(),
                    'doc_number' => $statement['DocNumber'] ?? 'N/A',
                ]);
                // ادامه می‌دهیم — یک statement خراب، بقیه را مسدود نمی‌کند
            }
        }

        // watermark فقط وقتی جلو می‌رود که **همه‌ی** ردیف‌ها پردازش شده باشند.
        // اگر حتی یکی خطا داد، بازه را باز نگه می‌داریم تا اجرای بعدی دوباره
        // ببیندش — تکرار بی‌خطر است چون `doc_number` یکتاست.
        if ($errors === 0) {
            $this->rememberPolledUpTo($to);
        } else {
            Log::warning('PollPodiumDepositsJob: watermark جلو نرفت چون پردازشِ بعضی ردیف‌ها خطا داد', [
                'errors' => $errors,
            ]);
        }

        Log::info('PollPodiumDepositsJob: پایان polling', [
            'total_incoming' => $total,
            'processed' => $processed,
            'errors' => $errors,
        ]);
    }

    /**
     * ابتدای پنجره — از watermarkِ آخرین polling موفق، نه از `now()-15min`.
     *
     * پیش از این پنجره ثابت بود: هر اجرا فقط ۱۵ دقیقه‌ی گذشته را می‌دید و **هیچ
     * جبرانی** نداشت. اگر worker یا scheduler چند ساعت می‌خوابید، آن پنجره‌ها
     * صرفاً از دست می‌رفتند — نه دیر، اصلاً خوانده نمی‌شدند. و طبق مستنداتِ خودِ
     * پروژه مرگِ worker بی‌صداست، پس هیچ‌کس متوجه نمی‌شد.
     *
     * هم‌پوشانیِ عمدی اضافه می‌شود تا تراکنشی دقیقاً روی مرز جا نماند؛ تکرار
     * بی‌خطر است چون `podium_deposit_records.doc_number` ایندکسِ یکتا دارد و
     * `ProcessPodiumDepositAction` ردیفِ تکراری را زودهنگام رد می‌کند.
     */
    private function windowStart(Carbon $to): Carbon
    {
        $cfg = (array) config('podium.deposit.statement', []);
        $overlap = max(0, (int) ($cfg['overlap_minutes'] ?? 5));
        $default = max(1, (int) ($cfg['default_window_minutes'] ?? 15));
        $maxLookback = max($default, (int) ($cfg['max_lookback_minutes'] ?? 10080));

        $watermark = Setting::query()
            ->where(Setting::KEY, self::WATERMARK_KEY)
            ->value(Setting::VALUE);

        $from = $watermark
            ? Carbon::parse($watermark)->subMinutes($overlap)
            : $to->copy()->subMinutes($default);

        // سقفِ عقبگرد: پس از یک قطعیِ طولانی، «کلِ تاریخ» را از بانک نمی‌خواهیم.
        $floor = $to->copy()->subMinutes($maxLookback);
        if ($from->lt($floor)) {
            Log::warning('PollPodiumDepositsJob: watermark از سقفِ عقبگرد قدیمی‌تر بود — بازه بریده شد', [
                'watermark' => $watermark,
                'max_lookback_minutes' => $maxLookback,
            ]);
            $from = $floor;
        }

        // ساعتِ سرور می‌تواند عقب برود؛ پنجره‌ی معکوس بی‌معناست.
        return $from->gt($to) ? $to->copy()->subMinutes($default) : $from;
    }

    private function rememberPolledUpTo(Carbon $to): void
    {
        Setting::updateOrCreate(
            [Setting::KEY => self::WATERMARK_KEY],
            [Setting::VALUE => $to->toIso8601String()],
        );
    }
}
