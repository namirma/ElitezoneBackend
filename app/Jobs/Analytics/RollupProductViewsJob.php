<?php

namespace App\Jobs\Analytics;

use App\Models\ProductView;
use App\Models\ProductViewDaily;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * بازدیدهای خام را روزانه در product_view_daily تجمیع می‌کند و سپس داده‌ی خامِ
 * قدیمی‌تر از retention_days را پاک می‌کند (حریم خصوصی + جلوگیری از رشد نامحدود).
 *
 * آنالیتیکس به‌جای کوئری روی raw، از این جدولِ سبک می‌خواند.
 */
class RollupProductViewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [60, 300];

    public function handle(): void
    {
        $this->rollupPendingDays();
        $this->pruneRawViews();
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RollupProductViewsJob failed after retries', [
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * هر روزی که هنوز در rollup ثبت نشده (تا و شامل دیروز) را تجمیع می‌کند.
     * با یک کوئریِ group-by-date (نه یک کوئری به‌ازای هر روز) و upsert idempotent
     * روی (product_id, view_date).
     */
    private function rollupPendingDays(): void
    {
        // آخرین روزی که قبلاً rollup شده؛ از روز بعدِ آن دوباره می‌سازیم تا روزِ ناقصِ
        // قبلی (که هنگام محاسبه هنوز تمام نشده بود) هم به‌روزرسانی شود.
        $lastRolled = ProductViewDaily::max(ProductViewDaily::VIEW_DATE);
        $from = $lastRolled
            ? Carbon::parse($lastRolled)->startOfDay()
            : (ProductView::min(ProductView::VIEWED_AT)
                ? Carbon::parse(ProductView::min(ProductView::VIEWED_AT))->startOfDay()
                : now()->startOfDay());

        $until = now()->startOfDay(); // روز جاری ناقص است، تجمیع نمی‌شود

        if ($from->gte($until)) {
            return;
        }

        $rows = ProductView::query()
            ->where(ProductView::VIEWED_AT, '>=', $from)
            ->where(ProductView::VIEWED_AT, '<', $until)
            ->select(
                ProductView::PRODUCT_ID,
                DB::raw('DATE(viewed_at) as view_date'),
                DB::raw('COUNT(*) as views'),
                DB::raw('COUNT(DISTINCT COALESCE(user_id, session_id, ip_address)) as unique_sessions')
            )
            ->groupBy(ProductView::PRODUCT_ID, DB::raw('DATE(viewed_at)'))
            ->get();

        foreach ($rows as $row) {
            ProductViewDaily::updateOrCreate(
                [
                    ProductViewDaily::PRODUCT_ID => $row->product_id,
                    ProductViewDaily::VIEW_DATE => $row->view_date,
                ],
                [
                    ProductViewDaily::VIEWS => (int) $row->views,
                    ProductViewDaily::UNIQUE_SESSIONS => (int) $row->unique_sessions,
                ]
            );
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
