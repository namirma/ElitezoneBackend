<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    const
        ID = 'id',
        PRODUCT_ID = 'product_id',
        NAME = 'name',
        SKU = 'sku',
        IS_ACTIVE = 'is_active',
        STOCK_QUANTITY = 'stock_quantity',
        VIEW_COUNT = 'view_count',
        IMAGES = 'images',
        VIDEOS = 'videos',
        MIN_ORDER_VALUE = 'min_order_value',
        MAX_ORDER_VALUE = 'max_order_value',
        LIMIT_ORDER_UNIT_ID = 'limit_order_unit_id',
        DISCOUNT_VALUE = 'discount_value',
        DISCOUNT_TYPE = 'discount_type',
        DISCOUNT_START_DATE = 'discount_start_date',
        DISCOUNT_END_DATE = 'discount_end_date',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_ID,
        self::NAME,
        self::SKU,
        self::IS_ACTIVE,
        self::STOCK_QUANTITY,
        self::VIEW_COUNT,
        self::IMAGES,
        self::VIDEOS,
        self::MIN_ORDER_VALUE,
        self::MAX_ORDER_VALUE,
        self::LIMIT_ORDER_UNIT_ID,
        self::DISCOUNT_VALUE,
        self::DISCOUNT_TYPE,
        self::DISCOUNT_START_DATE,
        self::DISCOUNT_END_DATE,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
        self::STOCK_QUANTITY => 'integer',
        self::VIEW_COUNT => 'integer',
        self::IMAGES => 'array',
        self::VIDEOS => 'array',
        self::MIN_ORDER_VALUE => 'float',
        self::MAX_ORDER_VALUE => 'float',
        self::DISCOUNT_VALUE => 'float',
        self::DISCOUNT_START_DATE => 'datetime',
        self::DISCOUNT_END_DATE => 'datetime',
    ];

    public function incrementViewCount(): void
    {
        $this->increment(self::VIEW_COUNT);
    }

    public function getActiveDiscountDetails(): ?array
    {
        $value = $this->{self::DISCOUNT_VALUE};
        $type = $this->{self::DISCOUNT_TYPE};

        if ($value === null || $value == 0 || $type === null) {
            return null;
        }

        $now = Carbon::now();
        $startDate = $this->{self::DISCOUNT_START_DATE};
        $endDate = $this->{self::DISCOUNT_END_DATE};

        if (($startDate instanceof Carbon && $startDate->isFuture()) || ($endDate instanceof Carbon && $endDate->isPast())) {
            return null;
        }

        if (!in_array($type, ['percentage', 'fixed_amount'])) {
            return null;
        }

        return ['type' => $type, 'value' => (float)$value];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, self::PRODUCT_ID);
    }

    public function limitOrderUnit(): BelongsTo
    {
        return $this->belongsTo(OrderUnit::class, self::LIMIT_ORDER_UNIT_ID);
    }

    public function variantAttributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'product_variant_attributes', 'product_variant_id', 'attribute_id')
            ->withPivot([Attribute::CREATED_AT, Attribute::UPDATED_AT])
            ->with('attributeType');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, ProductPrice::PRODUCT_VARIANT_ID, self::ID);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class, CartItem::PRODUCT_VARIANT_ID, self::ID);
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }
}
