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

    public function handle(): void
    {
        // دریافت آمار خرید کاربران
        $stats = Order::query()
            ->whereIn(Order::STATUS, [Order::STATUS_COMPLETED, Order::STATUS_DELIVERED, Order::STATUS_SHIPPED])
            ->select(Order::PLACER_USER_ID)
            ->selectRaw('MAX(created_at) as last_purchase_date')
            ->selectRaw('COUNT(id) as total_orders')
            ->selectRaw('SUM(final_amount) as total_spent')
            ->groupBy(Order::PLACER_USER_ID)
            ->get();

        foreach ($stats as $stat) {
            $userId = $stat->{Order::PLACER_USER_ID};

            // محاسبه امتیازات
            $lastPurchase = Carbon::parse($stat->last_purchase_date);
            $recency = (int) now()->diffInDays($lastPurchase);
            $frequency = (int) $stat->total_orders;
            $monetary = (float) $stat->total_spent;

            $rScore = $this->calculateRScore($recency);
            $fScore = $this->calculateFScore($frequency);
            $mScore = $this->calculateMScore($monetary);

            $newSegment = $this->determineSegment($rScore, $fScore);

            $existingStat = CustomerStat::where(CustomerStat::USER_ID, $userId)->first();

            $oldSegment = $existingStat ? $existingStat->segment : null;

            $customerStat = CustomerStat::updateOrCreate(
                [CustomerStat::USER_ID => $userId],
                [
                    CustomerStat::RECENCY_DAYS => $recency,
                    CustomerStat::FREQUENCY_COUNT => $frequency,
                    CustomerStat::MONETARY_TOTAL => $monetary,
                    CustomerStat::R_SCORE => $rScore,
                    CustomerStat::F_SCORE => $fScore,
                    CustomerStat::M_SCORE => $mScore,
                    CustomerStat::SEGMENT => $newSegment,
                    CustomerStat::LAST_CALCULATED_AT => now(),
                ]
            );

            if ($oldSegment && $oldSegment !== $newSegment) {

                $user = User::find($userId);

                if ($user) {
                    CustomerSegmentChanged::dispatch($user, $oldSegment, $newSegment);

                    Log::info("🔄 RFM Change Detected: User #{$userId} moved from [{$oldSegment}] to [{$newSegment}]");
                }
            }
        }
    }

    private function calculateRScore(int $days): int
    {
        return match (true) {
            $days <= 30 => 5,
            $days <= 60 => 4,
            $days <= 90 => 3,
            $days <= 180 => 2,
            default => 1,
        };
    }

    private function calculateFScore(int $count): int
    {
        return match (true) {
            $count >= 20 => 5,
            $count >= 10 => 4,
            $count >= 5 => 3,
            $count >= 2 => 2,
            default => 1,
        };
    }

    private function calculateMScore(float $amount): int
    {
        return match (true) {
            $amount >= 5_000_000_000 => 5,
            $amount >= 1_000_000_000 => 4,
            $amount >= 500_000_000 => 3,
            $amount >= 100_000_000 => 2,
            default => 1,
        };
    }

    private function determineSegment(int $r, int $f): string
    {
        if ($r >= 4 && $f >= 4) {
            return CustomerStat::SEGMENT_CHAMPION;
        }
        if ($f >= 3 && $r >= 3) {
            return CustomerStat::SEGMENT_LOYAL;
        }
        if ($r >= 4 && $f == 1) {
            return CustomerStat::SEGMENT_NEW;
        }
        if ($r <= 2 && $f >= 4) {
            return CustomerStat::SEGMENT_AT_RISK;
        }
        if ($r <= 2 && $f <= 2) {
            return CustomerStat::SEGMENT_HIBERNATING;
        }

        return CustomerStat::SEGMENT_POTENTIAL_LOYAL;
    }
}
