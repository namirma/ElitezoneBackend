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

    public const PRODUCT_VARIANT_ID = 'product_variant_id';

    public const SUPPLIER_USER_ID = 'supplier_user_id';

    public const PRODUCT_NAME = 'product_name';

    public const VARIANT_SKU = 'variant_sku';

    public const QUANTITY_DETAILS = 'quantity_details';

    public const WEIGHT = 'weight';

    public const QUANTITY = 'quantity';

    public const UNIT_NAME = 'unit_name';

    public const UNIT_PRICE = 'unit_price';

    public const TOTAL_PRICE = 'total_price';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::ORDER_ID,
        self::PRODUCT_VARIANT_ID,
        self::SUPPLIER_USER_ID,
        self::PRODUCT_NAME,
        self::VARIANT_SKU,
        self::QUANTITY_DETAILS,
        self::WEIGHT,
        self::QUANTITY,
        self::UNIT_NAME,
        self::UNIT_PRICE,
        self::TOTAL_PRICE,
    ];

    protected $casts = [
        self::QUANTITY_DETAILS => 'array',
        self::UNIT_PRICE => 'decimal:2',
        self::TOTAL_PRICE => 'decimal:2',
        self::WEIGHT => 'decimal:3',
        self::QUANTITY => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, self::SUPPLIER_USER_ID);
    }

    /**
     * جمع‌بندی تعداد کل اقلام
     */
    public function scopeSumQuantity(Builder $query)
    {
        return $query->sum('quantity');
    }
}
