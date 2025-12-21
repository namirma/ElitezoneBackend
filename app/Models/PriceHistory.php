<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    use HasFactory;

    const
        ID = 'id',
        PRODUCT_VARIANT_ID = 'product_variant_id',
        PRODUCT_PRICE_ID = 'product_price_id',
        FACTORY_ID = 'factory_id',
        FACTORY_LOCATION_ID = 'factory_location_id',
        GENERAL_PRICE = 'general_price',
        SPECIAL_PRICE = 'special_price',
        GOLDEN_FIGURE = 'golden_figure',
        USER_ID = 'user_id',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_VARIANT_ID,
        self::PRODUCT_PRICE_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::GENERAL_PRICE,
        self::SPECIAL_PRICE,
        self::GOLDEN_FIGURE,
        self::USER_ID,
    ];

    protected $casts = [
        self::GENERAL_PRICE => 'float', 
        self::SPECIAL_PRICE => 'float',
        self::GOLDEN_FIGURE => 'float',
        self::CREATED_AT    => 'datetime',
    ];

    /* -------------------- Relationships -------------------- */

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }

    public function productPrice(): BelongsTo
    {
        return $this->belongsTo(ProductPrice::class, self::PRODUCT_PRICE_ID);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, self::FACTORY_ID);
    }
}
