<?php

namespace App\Jobs\Analytics;

use App\Events\User\CustomerSegmentChanged;
use App\Models\CustomerStat;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateCustomerRfm implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * محاسبه RFM یک job سنگین است (روی همه کاربران).
     * اگر با شکست مواجه شد، بعد از ۵ دقیقه یک بار دیگر تلاش می‌کند.
     * اگر دوباره هم شکست خورد، در failed_jobs ثبت می‌شود.
     */
    public int $tries = 2;

    public array $backoff = [300];

    /** آستانه‌های امتیازدهی (در fixed از config، در quintile از توزیع داده). */
    private array $recencyBreaks = [];

    private array $frequencyBreaks = [];

    private array $monetaryBreaks = [];

    public function handle(): void
    {
        $statsQuery = $this->statsQuery();

        $mode = (string) config('intelligence.rfm.scoring_mode', 'fixed');
        if ($mode === 'quintile') {
            $this->computeQuintileBreaks();
        }

        $churnDays = (int) config('intelligence.rfm.churn_days', 180);

        // یک‌بار همه سگمنت‌های فعلی را load می‌کنیم تا در حلقه N+1 نداشته باشیم.
        $existingSegments = CustomerStat::pluck(CustomerStat::SEGMENT, CustomerStat::USER_ID)->all();

        // cursor() داده را به‌صورت استریم از دیتابیس می‌خواند؛ کل مجموعه هرگز یک‌جا
        // در حافظه بارگذاری نمی‌شود (برخلاف get() که با رشد دیتا منفجر می‌شد).
        foreach ($statsQuery->cursor() as $stat) {
            $userId = $stat->{Order::PLACER_USER_ID};

            $lastPurchase = Carbon::parse($stat->last_purchase_date);
            $recency = (int) abs(now()->diffInDays($lastPurchase));
            $frequency = (int) $stat->total_orders;
            $monetary = (float) $stat->total_spent;

            $rScore = $this->scoreRecency($recency);
            $fScore = $this->scoreFrequency($frequency);
            $mScore = $this->scoreMonetary($monetary);

            // churn صریح: اگر مدت‌هاست خریدی نکرده، صرف‌نظر از ماتریس «از دست رفته» است.
            $newSegment = $recency >= $churnDays
                ? CustomerStat::SEGMENT_LOST
                : $this->determineSegment($rScore, $fScore);

            $oldSegment = $existingSegments[$userId] ?? null;

            CustomerStat::updateOrCreate(
                [CustomerStat::USER_ID => $userId],
                [
                    CustomerStat::RECENCY_DAYS       => $recency,
                    CustomerStat::FREQUENCY_COUNT     => $frequency,
                    CustomerStat::MONETARY_TOTAL      => $monetary,
                    CustomerStat::R_SCORE             => $rScore,
                    CustomerStat::F_SCORE             => $fScore,
                    CustomerStat::M_SCORE             => $mScore,
                    CustomerStat::SEGMENT             => $newSegment,
                    CustomerStat::LAST_CALCULATED_AT  => now(),
                ]
            );

            // آرایه local رو sync نگه می‌داریم تا iteration بعدی سگمنت درست ببینه
            $existingSegments[$userId] = $newSegment;

            if ($oldSegment && $oldSegment !== $newSegment) {
                $user = User::find($userId);
                if ($user) {
                    try {
                        CustomerSegmentChanged::dispatch($user, $oldSegment, $newSegment);
                    } catch (\Throwable $e) {
                        Log::error("RFM segment event dispatch failed for user #{$userId}: ".$e->getMessage());
                    }
                    Log::info("RFM change: user #{$userId} [{$oldSegment}] -> [{$newSegment}]");
                }
            }
        }
    }

    /**
     * کوئری تجمیعِ آمار خرید هر کاربر (یک ردیف به‌ازای هر خریدار).
     */
    private function statsQuery()
    {
        return Order::query()
            ->whereIn(Order::STATUS, [Order::STATUS_COMPLETED, Order::STATUS_DELIVERED, Order::STATUS_SHIPPED])
            ->select(Order::PLACER_USER_ID)
            ->selectRaw('MAX(created_at) as last_purchase_date')
            ->selectRaw('COUNT(id) as total_orders')
            ->selectRaw('SUM(final_amount) as total_spent')
            ->groupBy(Order::PLACER_USER_ID);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scoring
    // ─────────────────────────────────────────────────────────────────────────

    private function scoreRecency(int $days): int
    {
        // quintile: مرزهای صعودیِ روز [q20..q80]؛ روزِ کمتر → امتیاز بالاتر (معکوس).
        if (! empty($this->recencyBreaks)) {
            foreach ([5, 4, 3, 2] as $i => $score) {
                if ($days <= ($this->recencyBreaks[$i] ?? PHP_INT_MAX)) {
                    return $score;
                }
            }

            return 1;
        }

        // fixed: کلید = امتیاز، مقدار = سقفِ روز.
        $breaks = (array) config('intelligence.rfm.recency');
        foreach ([5, 4, 3, 2] as $score) {
            if (isset($breaks[$score]) && $days <= $breaks[$score]) {
                return $score;
            }
        }

        return 1;
    }

    private function scoreFrequency(float $value): int
    {
        return $this->scoreAscending($value, $this->frequencyBreaks, (array) config('intelligence.rfm.frequency'));
    }

    private function scoreMonetary(float $value): int
    {
        return $this->scoreAscending($value, $this->monetaryBreaks, (array) config('intelligence.rfm.monetary'));
    }

    /**
     * امتیازدهی صعودی (frequency / monetary): مقدار بیشتر → امتیاز بالاتر.
     *
     * @param  array<int,float>  $quintileBreaks  مرزهای صعودی [q20,q40,q60,q80] (حالت quintile)
     * @param  array<int,float>  $fixedBreaks  کلید=امتیاز، مقدار=کف بازه (حالت fixed)
     */
    private function scoreAscending(float $value, array $quintileBreaks, array $fixedBreaks): int
    {
        if (! empty($quintileBreaks)) {
            foreach ([1, 2, 3, 4] as $idx) {
                if ($value < ($quintileBreaks[$idx - 1] ?? PHP_INT_MAX)) {
                    return $idx;
                }
            }

            return 5;
        }

        foreach ([5, 4, 3, 2] as $score) {
            if (isset($fixedBreaks[$score]) && $value >= $fixedBreaks[$score]) {
                return $score;
            }
        }

        return 1;
    }

    /**
     * مرزهای پنجک را از توزیع واقعیِ داده محاسبه می‌کند (فقط در حالت quintile).
     * فقط سه ستون عددی pluck می‌شود، نه مدل کامل.
     */
    private function computeQuintileBreaks(): void
    {
        $rows = $this->statsQuery()->get(['last_purchase_date', 'total_orders', 'total_spent']);

        if ($rows->isEmpty()) {
            return;
        }

        $recencies = [];
        $freqs = [];
        $monies = [];
        foreach ($rows as $r) {
            $recencies[] = (int) abs(now()->diffInDays(Carbon::parse($r->last_purchase_date)));
            $freqs[] = (int) $r->total_orders;
            $monies[] = (float) $r->total_spent;
        }

        $this->recencyBreaks = $this->quantiles($recencies);   // صعودی روز
        $this->frequencyBreaks = $this->quantiles($freqs);
        $this->monetaryBreaks = $this->quantiles($monies);
    }

    /**
     * مرزهای ۲۰/۴۰/۶۰/۸۰ درصدِ یک آرایه (۴ مرز → ۵ پنجک).
     *
     * @return array<int,float> چهار مرز صعودی
     */
    private function quantiles(array $values): array
    {
        sort($values);
        $n = count($values);
        $breaks = [];
        foreach ([0.2, 0.4, 0.6, 0.8] as $p) {
            $idx = (int) floor($p * ($n - 1));
            $breaks[] = $values[$idx];
        }

        return $breaks;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Segmentation — ماتریس صریح ۵×۵ (R × F): بدون همپوشانی، بدون حفره، شامل lost
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return string یکی از ثابت‌های CustomerStat::SEGMENT_*
     */
    private function determineSegment(int $r, int $f): string
    {
        $matrix = [
            // R=5 (تازه‌ترین)
            5 => [1 => CustomerStat::SEGMENT_NEW,         2 => CustomerStat::SEGMENT_POTENTIAL_LOYAL, 3 => CustomerStat::SEGMENT_POTENTIAL_LOYAL, 4 => CustomerStat::SEGMENT_CHAMPION,    5 => CustomerStat::SEGMENT_CHAMPION],
            4 => [1 => CustomerStat::SEGMENT_NEW,         2 => CustomerStat::SEGMENT_POTENTIAL_LOYAL, 3 => CustomerStat::SEGMENT_LOYAL,           4 => CustomerStat::SEGMENT_CHAMPION,    5 => CustomerStat::SEGMENT_CHAMPION],
            3 => [1 => CustomerStat::SEGMENT_POTENTIAL_LOYAL, 2 => CustomerStat::SEGMENT_POTENTIAL_LOYAL, 3 => CustomerStat::SEGMENT_LOYAL,       4 => CustomerStat::SEGMENT_LOYAL,       5 => CustomerStat::SEGMENT_LOYAL],
            2 => [1 => CustomerStat::SEGMENT_HIBERNATING, 2 => CustomerStat::SEGMENT_HIBERNATING,     3 => CustomerStat::SEGMENT_AT_RISK,         4 => CustomerStat::SEGMENT_AT_RISK,     5 => CustomerStat::SEGMENT_AT_RISK],
            1 => [1 => CustomerStat::SEGMENT_LOST,        2 => CustomerStat::SEGMENT_LOST,            3 => CustomerStat::SEGMENT_AT_RISK,         4 => CustomerStat::SEGMENT_AT_RISK,     5 => CustomerStat::SEGMENT_AT_RISK],
        ];

        $r = max(1, min(5, $r));
        $f = max(1, min(5, $f));

        return $matrix[$r][$f];
    }
}
