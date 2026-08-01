<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;

class PriceFilterCategory extends Model
{
    const ID = 'id';

    const NAME = 'name';

    const CODE = 'code';

    const DESCRIPTION = 'description';

    const IS_ACTIVE = 'is_active';

    const SORT_ORDER = 'sort_order';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME, self::CODE, self::DESCRIPTION, self::IS_ACTIVE, self::SORT_ORDER,
    ];

    /**
     * دو کش با هم فراموش می‌شوند — نه فقط کشِ موتور قیمت‌گذاری.
     *
     * `pricing:categories_with_filters` را پایپ‌لاین قیمت می‌خواند و از قبل
     * فلاش می‌شد، ولی `price_filter_categories` (کشِ `GET /price-filters`، همان
     * چیزی که صفحهٔ محصول برچسب‌های تخفیف را از آن می‌سازد) هیچ‌جا باطل نمی‌شد.
     * نتیجه: ادمین مبلغِ فیلتر را عوض می‌کرد، سبد **فوراً** نرخ جدید را می‌گرفت و
     * کاتالوگ تا یک ساعت نرخ قدیمی را تبلیغ می‌کرد — یعنی کاربر یک عدد می‌دید و
     * عدد دیگری می‌پرداخت.
     */
    protected static function booted(): void
    {
        $flush = function (): void {
            Cache::forget('pricing:categories_with_filters');
            Cache::forget('price_filter_categories');
        };

        static::saved($flush);
        static::deleted($flush);
    }

    public function filters(): BelongsToMany
    {
        return $this->belongsToMany(
            PriceFilter::class,
            'price_filter_category_items',
            'price_filter_category_id',
            'price_filter_id'
        )->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }
}
