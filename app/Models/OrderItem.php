<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    public const
        ID = 'id',
        ORDER_ID = 'order_id',
        PRODUCT_VARIANT_ID = 'product_variant_id',
        SUPPLIER_USER_ID = 'supplier_user_id',
        PRODUCT_NAME = 'product_name',
        VARIANT_SKU = 'variant_sku',
        QUANTITY_DETAILS = 'quantity_details',
        UNIT_PRICE = 'unit_price',
        TOTAL_PRICE = 'total_price',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::ORDER_ID,
        self::PRODUCT_VARIANT_ID,
        self::SUPPLIER_USER_ID,
        self::PRODUCT_NAME,
        self::VARIANT_SKU,
        self::QUANTITY_DETAILS,
        self::UNIT_PRICE,
        self::TOTAL_PRICE,
    ];

    protected $casts = [
        self::QUANTITY_DETAILS => 'array',
        self::UNIT_PRICE => 'decimal:2',
        self::TOTAL_PRICE => 'decimal:2',
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
    public function scopeSumQuantity(Builder $query, string $alias = 'total_qty')
{
    return $query->selectRaw("SUM(JSON_EXTRACT(quantity_details, '$.quantity')) as {$alias}");
}
}
