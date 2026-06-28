<?php

namespace App\Models;

use App\Services\Pricing\Pipes\SetBasePrice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductPrice extends Model
{
    use HasFactory;

    const ID = 'id';

    const PRODUCT_VARIANT_ID = 'product_variant_id';

    const FACTORY_ID = 'factory_id';

    const FACTORY_LOCATION_ID = 'factory_location_id';

    const GENERAL_PRICE = 'general_price';

    const SPECIAL_PRICE = 'special_price';

    const GOLDEN_FIGURE = 'golden_figure';

    const LAST_UPDATED_AT = 'last_updated_at';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_VARIANT_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::GENERAL_PRICE,
        self::SPECIAL_PRICE,
        self::GOLDEN_FIGURE,
        self::LAST_UPDATED_AT,
    ];

    // ستون‌های پولی decimal هستند — cast float خطای نمایش IEEE-754 وارد می‌کرد
    protected $casts = [
        self::LAST_UPDATED_AT => 'datetime',
        self::GENERAL_PRICE => 'decimal:2',
        self::SPECIAL_PRICE => 'decimal:2',
        self::GOLDEN_FIGURE => 'decimal:2',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::created(fn (ProductPrice $price) => self::flushVariantCache($price));
        static::updated(fn (ProductPrice $price) => self::flushVariantCache($price));
        static::deleted(fn (ProductPrice $price) => self::flushVariantCache($price));
    }

    /**
     * پاکسازی هوشمند کش (سازگار با همه درایورها)
     */
    protected static function flushVariantCache(ProductPrice $price): void
    {
        if (! isset($price->product_variant_id)) {
            return;
        }
        $driver = config('cache.default');

        if (in_array($driver, ['redis', 'memcached'])) {
            try {
                Cache::tags(["variant:{$price->product_variant_id}"])->flush();

                return;
            } catch (\Exception $e) {
                Log::error('Failed to flush cache tags: '.$e->getMessage());
            }
        }

        // درایورهای بدون tag (file, database, ...) — حذف مستقیم کلید قیمت پایه؛
        // بدون این، قیمت کش‌شده تا یک ساعت بعد از تغییر قیمت کهنه می‌ماند.
        if (isset($price->factory_id, $price->factory_location_id)) {
            Cache::forget(SetBasePrice::cacheKey(
                (int) $price->product_variant_id,
                (int) $price->factory_id,
                (int) $price->factory_location_id,
            ));
        }
    }

    /* -------------------- Relationships -------------------- */

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, self::FACTORY_ID);
    }

    public function factoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, self::FACTORY_LOCATION_ID);
    }
}
