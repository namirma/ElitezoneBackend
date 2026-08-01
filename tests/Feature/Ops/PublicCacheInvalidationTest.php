<?php

namespace Tests\Feature\Ops;

use App\Models\Badge;
use App\Models\CustomerType;
use App\Models\PriceFilterCategory;
use App\Models\PriceFilterType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * چهار کشِ عمومی که هیچ‌جا باطل نمی‌شدند.
 *
 * `GET /price-filters`, `/price-filters/types`, `/customer-types` و فهرست نشان‌ها
 * یک ساعت کش می‌شوند، ولی هیچ فراخوانِ `Cache::forget` برای این کلیدها در پروژه
 * وجود نداشت. بدترین حالتش فیلترهای قیمت بود: پایپ‌لاینِ قیمت کشِ *خودش* را فلاش
 * می‌کرد، پس سبد فوراً نرخ جدید را می‌گرفت در حالی که کاتالوگ تا یک ساعت نرخ
 * قدیمی را تبلیغ می‌کرد — کاربر یک عدد می‌دید و عدد دیگری می‌پرداخت.
 */
class PublicCacheInvalidationTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function saving_a_price_filter_category_clears_both_caches(): void
    {
        Cache::put('price_filter_categories', 'stale', 3600);
        Cache::put('pricing:categories_with_filters', 'stale', 3600);

        PriceFilterCategory::create([
            'name' => 'دستهٔ تست',
            'code' => 'pfc_'.Str::random(8),
        ]);

        $this->assertNull(Cache::get('price_filter_categories'), 'کشِ عمومی باید باطل شود.');
        $this->assertNull(Cache::get('pricing:categories_with_filters'), 'کشِ پایپ‌لاین هم مثل قبل.');
    }

    #[Test]
    public function saving_a_price_filter_type_clears_its_public_cache(): void
    {
        Cache::put('price_filter_types', 'stale', 3600);

        PriceFilterType::create(['name' => 'نوع تست', 'code' => 'pft_'.Str::random(8)]);

        $this->assertNull(Cache::get('price_filter_types'));
    }

    #[Test]
    public function saving_a_customer_type_clears_its_public_cache(): void
    {
        Cache::put('customer_types', 'stale', 3600);

        CustomerType::create(['name' => 'مشتری تست', 'code' => 'ct_'.Str::random(8)]);

        $this->assertNull(Cache::get('customer_types'));
    }

    #[Test]
    public function saving_a_badge_clears_its_public_cache(): void
    {
        Cache::put('badges', 'stale', 3600);

        Badge::create(['name' => 'نشان تست', 'code' => 'bdg_'.Str::random(8)]);

        $this->assertNull(Cache::get('badges'));
    }
}
