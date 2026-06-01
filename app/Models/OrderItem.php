<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const ORDER_ID = 'order_id';

    public const SHIPMENT_ID = 'shipment_id';

    public const PRODUCT_VARIANT_ID = 'product_variant_id';

    public const SUPPLIER_USER_ID = 'supplier_user_id';

    public const FACTORY_ID = 'factory_id';

    public const FACTORY_LOCATION_ID = 'factory_location_id';

    public const PRODUCT_NAME = 'product_name';

    public const VARIANT_SKU = 'variant_sku';

    public const QUANTITY_DETAILS = 'quantity_details';

    public const WEIGHT = 'weight';

    public const QUANTITY = 'quantity';

    public const UNIT_NAME = 'unit_name';

    public const UNIT_PRICE = 'unit_price';

    public const TOTAL_PRICE = 'total_price';

    public const SHIPPING_COST = 'shipping_cost';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::ORDER_ID,
        self::SHIPMENT_ID,
        self::PRODUCT_VARIANT_ID,
        self::SUPPLIER_USER_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::PRODUCT_NAME,
        self::VARIANT_SKU,
        self::QUANTITY_DETAILS,
        self::WEIGHT,
        self::QUANTITY,
        self::UNIT_NAME,
        self::UNIT_PRICE,
        self::TOTAL_PRICE,
        self::SHIPPING_COST,
    ];

    protected $casts = [
        self::QUANTITY_DETAILS => 'array',
        self::UNIT_PRICE => 'decimal:2',
        self::TOTAL_PRICE => 'decimal:2',
        self::SHIPPING_COST => 'decimal:2',
        self::WEIGHT => 'decimal:3',
        self::QUANTITY => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, self::SHIPMENT_ID);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, self::SUPPLIER_USER_ID);
    }

    // NOTE: do not name this relation factory() — it would collide with the
    // HasFactory trait's static factory() method and break OrderItem::factory().
    public function originFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, self::FACTORY_ID);
    }

    public function originFactoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, self::FACTORY_LOCATION_ID);
    }

    /**
     * جمع‌بندی تعداد کل اقلام
     */
    public function scopeSumQuantity(Builder $query)
    {
        return $query->sum('quantity');
    }
}
