<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Visus\Cuid2\Cuid2;

/**
 * @property int $id
 * @property string $cuid
 * @property int $category_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $shipping_promo
 * @property string $sku
 * @property bool $is_active
 * @property bool $is_featured
 * @property int $view_count
 * @property int $items_per_page
 * @property numeric|null $aggregate_min_order_value
 * @property numeric|null $aggregate_max_order_value
 * @property int|null $aggregate_limit_order_unit_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OrderUnit|null $aggregateLimitOrderUnit
 * @property-read ProductCategory $category
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read Collection<int, ProductImage> $images
 * @property-read int|null $images_count
 * @property-read Collection<int, Offer> $offers
 * @property-read int|null $offers_count
 * @property-read Collection<int, OrderUnit> $orderUnits
 * @property-read int|null $order_units_count
 * @property-read Collection<int, ProductPrice> $variantPrices
 * @property-read int|null $variant_prices_count
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read int|null $variants_count
 * @property-read Collection<int, ProductVideo> $videos
 * @property-read int|null $videos_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product featured()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereAggregateLimitOrderUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereAggregateMaxOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereAggregateMinOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsFeatured($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereItemsPerPage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereShippingPromo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereViewCount($value)
 *
 * @mixin \Eloquent
 */
class Product extends Model
{
    use HasFactory, PersianDate;

    const ID = 'id';

    const CATEGORY_ID = 'category_id';

    const NAME = 'name';

    const SLUG = 'slug';

    const DESCRIPTION = 'description';

    const SHIPPING_PROMO = 'shipping_promo';

    const SKU = 'sku';

    const IS_ACTIVE = 'is_active';

    const IS_FEATURED = 'is_featured';

    const VIEW_COUNT = 'view_count';

    const ITEMS_PER_PAGE = 'items_per_page';

    const AGGREGATE_MIN_ORDER_VALUE = 'aggregate_min_order_value';

    const AGGREGATE_MAX_ORDER_VALUE = 'aggregate_max_order_value';

    const AGGREGATE_LIMIT_ORDER_UNIT_ID = 'aggregate_limit_order_unit_id';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    const CUID = 'cuid';

    protected $fillable = [
        self::CATEGORY_ID,
        self::NAME,
        self::SLUG,
        self::DESCRIPTION,
        self::SHIPPING_PROMO,
        self::SKU,
        self::IS_ACTIVE,
        self::IS_FEATURED,
        self::VIEW_COUNT,
        self::ITEMS_PER_PAGE,
        self::AGGREGATE_MIN_ORDER_VALUE,
        self::AGGREGATE_MAX_ORDER_VALUE,
        self::AGGREGATE_LIMIT_ORDER_UNIT_ID,
        self::CUID,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
        self::IS_FEATURED => 'boolean',
        self::VIEW_COUNT => 'integer',
        self::ITEMS_PER_PAGE => 'integer',
        self::AGGREGATE_MIN_ORDER_VALUE => 'decimal:2',
        self::AGGREGATE_MAX_ORDER_VALUE => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $model) {
            $model->{self::CUID} = (string) new Cuid2;
        });
    }

    public function getRouteKeyName(): string
    {
        return self::CUID;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, self::CATEGORY_ID);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, ProductImage::PRODUCT_ID, self::ID);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class, ProductReview::PRODUCT_ID, self::ID);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(ProductVideo::class, ProductVideo::PRODUCT_ID, self::ID);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, ProductVariant::PRODUCT_ID, self::ID);
    }

    public function orderUnits(): BelongsToMany
    {
        return $this->belongsToMany(OrderUnit::class, 'product_order_units', 'product_id', 'order_unit_id')
            ->withPivot('max_quantity')
            ->withTimestamps();
    }

    public function variantPrices(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProductPrice::class,
            ProductVariant::class,
            self::ID,
            ProductPrice::PRODUCT_VARIANT_ID,
            self::ID,
            ProductVariant::ID
        );
    }

    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(Offer::class, 'product_offers', 'product_id', 'offer_id')
            ->withPivot('associated_at')
            ->withTimestamps();
    }

    public function aggregateLimitOrderUnit(): BelongsTo
    {
        return $this->belongsTo(OrderUnit::class, self::AGGREGATE_LIMIT_ORDER_UNIT_ID);
    }

    /**
     * درخواست‌های تغییرِ تأمین‌کننده روی این محصول (ویرایشِ خودش یا واریانت‌هایش).
     *
     * @return HasMany<ProductChangeRequest, $this>
     */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(ProductChangeRequest::class, ProductChangeRequest::PRODUCT_ID, self::ID);
    }

    /**
     * فقط بازها — `withCount('pendingChangeRequests')` به پنلِ تأمین‌کننده می‌گوید
     * روی کدام ردیف «در انتظار تأیید» بگذارد.
     *
     * @return HasMany<ProductChangeRequest, $this>
     */
    public function pendingChangeRequests(): HasMany
    {
        return $this->changeRequests()->where(ProductChangeRequest::STATUS, ProductChangeRequest::STATUS_PENDING);
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    public function scopeFeatured($query)
    {
        return $query->where(self::IS_FEATURED, true);
    }

    public function incrementViewCount(): void
    {
        $this->increment(self::VIEW_COUNT);
    }

    public function getCreatedAtAttribute($value)
    {
        if (! in_array('created_at_p', $this->appends)) {
            $this->appends[] = 'created_at_p';
        }
        if (! in_array('created_at_diff', $this->appends)) {
            $this->appends[] = 'created_at_diff';
        }

        return $value;
    }

    public function getUpdatedAtAttribute($value)
    {
        if (! in_array('updated_at_p', $this->appends)) {
            $this->appends[] = 'updated_at_p';
        }
        if (! in_array('updated_at_diff', $this->appends)) {
            $this->appends[] = 'updated_at_diff';
        }

        return $value;
    }
}
