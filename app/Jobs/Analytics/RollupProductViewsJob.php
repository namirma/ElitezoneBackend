<?php

namespace App\Jobs\Analytics;

use App\Models\ProductView;
use App\Models\ProductViewDaily;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * بازدیدهای خام را روزانه در product_view_daily تجمیع می‌کند و سپس داده‌ی خامِ
 * قدیمی‌تر از retention_days را پاک می‌کند (حریم خصوصی + جلوگیری از رشد نامحدود).
 *
 * آنالیتیکس به‌جای کوئری روی raw، از این جدولِ سبک می‌خواند.
 */
class RollupProductViewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $this->rollupPendingDays();
        $this->pruneRawViews();
    }

    /**
     * هر روزی که هنوز در rollup ثبت نشده (تا و شامل دیروز) را تجمیع می‌کند.
     * idempotent است: upsert روی (product_id, view_date).
     */
    private function rollupPendingDays(): void
    {
        // آخرین روزی که قبلاً rollup شده.
        $lastRolled = ProductViewDaily::max(ProductViewDaily::VIEW_DATE);
        $from = $lastRolled
            ? \Illuminate\Support\Carbon::parse($lastRolled)->startOfDay()
            : (ProductView::min(ProductView::VIEWED_AT)
                ? \Illuminate\Support\Carbon::parse(ProductView::min(ProductView::VIEWED_AT))->startOfDay()
                : now()->startOfDay());

        $until = now()->startOfDay(); // تا ابتدای امروز (روز جاری ناقص است، تجمیع نمی‌شود)

        for ($day = $from->copy(); $day->lt($until); $day->addDay()) {
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();

            $rows = ProductView::query()
                ->whereBetween(ProductView::VIEWED_AT, [$dayStart, $dayEnd])
                ->select(
                    ProductView::PRODUCT_ID,
                    DB::raw('COUNT(*) as views'),
                    DB::raw('COUNT(DISTINCT COALESCE(user_id, session_id, ip_address)) as unique_sessions')
                )
                ->groupBy(ProductView::PRODUCT_ID)
                ->get();

            foreach ($rows as $row) {
                ProductViewDaily::updateOrCreate(
                    [
                        ProductViewDaily::PRODUCT_ID => $row->product_id,
                        ProductViewDaily::VIEW_DATE => $dayStart->toDateString(),
                    ],
                    [
                        ProductViewDaily::VIEWS => (int) $row->views,
                        ProductViewDaily::UNIQUE_SESSIONS => (int) $row->unique_sessions,
                    ]
                );
            }
        }
    }

    private function pruneRawViews(): void
    {
        $retentionDays = (int) config('intelligence.product_views.retention_days', 90);
        if ($retentionDays <= 0) {
            return;
        }

        // حذف به‌صورت chunk تا روی جدول بزرگ، لاک طولانی ایجاد نشود.
        do {
            $deleted = ProductView::where(ProductView::VIEWED_AT, '<', now()->subDays($retentionDays))
                ->limit(5000)
                ->delete();
        } while ($deleted > 0);
    }
}
