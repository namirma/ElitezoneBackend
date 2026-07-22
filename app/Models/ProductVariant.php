<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property string $sku
 * @property bool $is_active
 * @property int $stock_quantity
 * @property int $view_count
 * @property numeric|null $discount_value
 * @property string|null $discount_type
 * @property Carbon|null $discount_start_date
 * @property Carbon|null $discount_end_date
 * @property numeric|null $min_order_value
 * @property numeric|null $max_order_value
 * @property int|null $limit_order_unit_id
 * @property array<array-key, mixed>|null $images
 * @property array<array-key, mixed>|null $videos
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, CartItem> $cartItems
 * @property-read int|null $cart_items_count
 * @property-read OrderUnit|null $limitOrderUnit
 * @property-read Collection<int, ProductPrice> $prices
 * @property-read int|null $prices_count
 * @property-read Product $product
 * @property-read Collection<int, StockSubscription> $stockSubscriptions
 * @property-read int|null $stock_subscriptions_count
 * @property-read ProductVariantAttribute|null $details
 * @property-read Collection<int, Attribute> $variantAttributes
 * @property-read int|null $variant_attributes_count
 * @property-read Collection<int, Wishlist> $wishlists
 * @property-read int|null $wishlists_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereDiscountEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereDiscountStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereDiscountType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereDiscountValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereImages($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereLimitOrderUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereMaxOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereMinOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereStockQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereVideos($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereViewCount($value)
 *
 * @mixin \Eloquent
 */
class ProductVariant extends Model
{
    use HasFactory;

    const ID = 'id';

    const PRODUCT_ID = 'product_id';

    const NAME = 'name';

    const SKU = 'sku';

    const IS_ACTIVE = 'is_active';

    const STOCK_QUANTITY = 'stock_quantity';

    const VIEW_COUNT = 'view_count';

    const IMAGES = 'images';

    const VIDEOS = 'videos';

    const MIN_ORDER_VALUE = 'min_order_value';

    const MAX_ORDER_VALUE = 'max_order_value';

    const LIMIT_ORDER_UNIT_ID = 'limit_order_unit_id';

    const DISCOUNT_VALUE = 'discount_value';

    const DISCOUNT_TYPE = 'discount_type';

    const DISCOUNT_START_DATE = 'discount_start_date';

    const DISCOUNT_END_DATE = 'discount_end_date';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

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
        self::MIN_ORDER_VALUE => 'decimal:2',
        self::MAX_ORDER_VALUE => 'decimal:2',
        self::DISCOUNT_VALUE => 'decimal:2',
        self::DISCOUNT_START_DATE => 'datetime',
        self::DISCOUNT_END_DATE => 'datetime',
    ];

    public function incrementViewCount(): void
    {
        $this->increment(self::VIEW_COUNT);
    }

    public function getActiveDiscountDetails(): ?array
    {

        if (empty($this->discount_value) || $this->discount_value <= 0) {
            return null;
        }

        $now = now();

        if (! empty($this->discount_start_date) && $now->lt($this->discount_start_date)) {
            return null;
        }

        if (! empty($this->discount_end_date) && $now->gt($this->discount_end_date)) {
            return null;
        }

        return [
            'type' => $this->discount_type ?? 'fixed_amount',
            'value' => $this->discount_value,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, self::PRODUCT_ID);
    }

    public function limitOrderUnit(): BelongsTo
    {
        return $this->belongsTo(OrderUnit::class, self::LIMIT_ORDER_UNIT_ID);
    }

    public function variantAttributes()
    {
        return $this->belongsToMany(Attribute::class, 'product_variant_attributes')
            ->using(ProductVariantAttribute::class)
            ->withPivot('attribute_value_id')
            ->as('details');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, ProductPrice::PRODUCT_VARIANT_ID, self::ID);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class, CartItem::PRODUCT_VARIANT_ID, self::ID);
    }

    public function wishlists(): BelongsToMany
    {
        return $this->belongsToMany(Wishlist::class, 'product_wishlist', 'product_variant_id', 'wishlist_id')
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    public function stockSubscriptions(): HasMany
    {
        return $this->hasMany(StockSubscription::class, StockSubscription::PRODUCT_VARIANT_ID);
    }
}
