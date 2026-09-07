<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Search;
use Illuminate\Console\Command;

/**
 * حذف لاگ‌های قدیمیِ جستجو.
 *
 * ⚠️ **چرا لازم است.** `GET /api/search` عمومی است و `logSearchActivity()` به‌ازای
 * **هر** درخواستِ جستجو یک ردیف در `searches` می‌نویسد: عبارتِ تایپ‌شده،
 * `user_id`، IP (ناشناس‌شده) و User-Agent. هیچ سیاستِ نگهداشتی برای این جدول
 * وجود نداشت — نه کرونی، نه کلیدِ کانفیگی.
 *
 * این دقیقاً همان شکافی است که برای `carts` (سبدِ مهمان) و `product_views` قبلاً
 * بسته شده بود؛ `searches` جا مانده بود. دو پیامد داشت:
 *
 *  1. رشدِ نامحدودِ جدول — با throttle:30,1 و بدونِ سقفِ تعدادِ IP، ورودی واقعی است.
 *  2. نگه‌داشتنِ **ابدیِ** تاریخچه‌ی جستجوی کاربران، در حالی که برای دادهٔ
 *     هم‌جنسِ `product_views` سقفِ ۹۰ روز تعریف شده بود.
 *
 * حذف روی `created_at` است و chunk-شده، تا یک اجرا روی جدولِ انباشته قفلِ
 * طولانی نسازد. مثل `carts:prune-guests` تا ته می‌رود، نه فقط یک chunk.
 */
class PruneSearchLogs extends Command
{
    protected $signature = 'searches:prune {--days= : بازهٔ نگهداشت (پیش‌فرض از config/intelligence.php)}';

    protected $description = 'حذف لاگ‌های جستجوی قدیمی‌تر از بازهٔ نگهداشت';

    private const CHUNK = 1000;

    /** گاردِ حلقهٔ بی‌پایان اگر حذف بی‌اثر بماند. */
    private const MAX_ITERATIONS = 10000;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('intelligence.searches.retention_days', 90));

        if ($days < 1) {
            $this->error('بازهٔ نگهداشت باید حداقل ۱ روز باشد.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $ids = Search::query()
                ->where(Search::CREATED_AT, '<', $cutoff)
                ->orderBy(Search::ID)
                ->limit(self::CHUNK)
                ->pluck(Search::ID);

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += Search::whereIn(Search::ID, $ids)->delete();
        }

        // پیامِ کنسول مثل بقیهٔ کامندهای این پروژه به‌صورت رشتهٔ درجا است
        // (خروجیِ اپراتور، نه پاسخِ API — پس کلیدِ ترجمه لازم ندارد).
        $this->info("حذف {$deleted} لاگ جستجوی قدیمی‌تر از {$days} روز.");

        return self::SUCCESS;
    }
}
