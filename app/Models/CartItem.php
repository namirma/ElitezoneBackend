<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    const
        ID                   = 'id',
        CART_ID              = 'cart_id',
        PRODUCT_VARIANT_ID   = 'product_variant_id',
        FACTORY_ID           = 'factory_id',
        FACTORY_LOCATION_ID  = 'factory_location_id',
        SHIPPING_METHOD_ID   = 'shipping_method_id',
        QUANTITIES           = 'quantities',
        PRICE                = 'price',
        FINAL_PRICE          = 'final_price',
        VAT_AMOUNT           = 'vat_amount',
        TOTAL_WITH_VAT       = 'total_with_vat',
        WITH_VAT             = 'with_vat',
        CREATED_AT           = 'created_at',
        UPDATED_AT           = 'updated_at';

    protected $fillable = [
        self::CART_ID,
        self::PRODUCT_VARIANT_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::SHIPPING_METHOD_ID,
        self::QUANTITIES,
        self::PRICE,
        self::FINAL_PRICE,
        self::VAT_AMOUNT,
        self::TOTAL_WITH_VAT,
        self::WITH_VAT,
    ];

    protected $casts = [
        self::QUANTITIES => 'array',
        self::WITH_VAT => 'boolean',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function factoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
