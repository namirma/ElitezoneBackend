<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductPrice extends Model
{
    use HasFactory;

    const
        ID = 'id',
        PRODUCT_VARIANT_ID = 'product_variant_id',
        FACTORY_ID = 'factory_id',
        FACTORY_LOCATION_ID = 'factory_location_id',
        GENERAL_PRICE = 'general_price',
        SPECIAL_PRICE = 'special_price',
        GOLDEN_FIGURE = 'golden_figure',
        LAST_UPDATED_AT = 'last_updated_at',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_VARIANT_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::GENERAL_PRICE,
        self::SPECIAL_PRICE,
        self::GOLDEN_FIGURE,
        self::LAST_UPDATED_AT
    ];

    protected $casts = [
        self::LAST_UPDATED_AT => 'datetime',
        self::GENERAL_PRICE   => 'float',
        self::SPECIAL_PRICE   => 'float',
        self::GOLDEN_FIGURE   => 'float',
    ];


    protected static function booted(): void
    {
        parent::booted();

        static::created(fn(ProductPrice $price) => self::flushVariantCache($price));
        static::updated(fn(ProductPrice $price) => self::flushVariantCache($price));
        static::deleted(fn(ProductPrice $price) => self::flushVariantCache($price));
    }

    /**
     * پاکسازی هوشمند کش (سازگار با همه درایورها)
     */
    protected static function flushVariantCache(ProductPrice $price): void
    {
        if (!isset($price->product_variant_id)) return;
        $driver = config('cache.default');

        if (in_array($driver, ['redis', 'memcached'])) {
            try {
                Cache::tags(["variant:{$price->product_variant_id}"])->flush();
            } catch (\Exception $e) {
                Log::error("Failed to flush cache tags: " . $e->getMessage());
            }
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
