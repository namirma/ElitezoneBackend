<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Visus\Cuid2\Cuid2;

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
