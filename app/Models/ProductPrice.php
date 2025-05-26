<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
    /**
     * Constants for table columns.
     */
    public const
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

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        self::PRODUCT_VARIANT_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::GENERAL_PRICE,
        self::SPECIAL_PRICE,
        self::GOLDEN_FIGURE,
        self::LAST_UPDATED_AT
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        self::LAST_UPDATED_AT => 'datetime',
        self::GENERAL_PRICE   => 'float', // پیشنهاد: قیمت‌ها را cast کنید
        self::SPECIAL_PRICE   => 'float', // پیشنهاد: قیمت‌ها را cast کنید
        self::GOLDEN_FIGURE   => 'float', // پیشنهاد: قیمت‌ها را cast کنید
    ];

    /**
     * Get the product variant that owns the price.
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }

    /**
     * Get the factory that owns the price.
     */
    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, self::FACTORY_ID);
    }

    /**
     * Get the factory location that owns the price.
     */
    public function factoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, self::FACTORY_LOCATION_ID);
    }
}
