<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    use HasFactory, PersianDate;

    const
        ID = 'id',
        CATEGORY_ID = 'category_id',
        NAME = 'name',
        SLUG = 'slug',
        DESCRIPTION = 'description',
        SKU = 'sku',
        IS_ACTIVE = 'is_active',
        IS_FEATURED = 'is_featured',
        VIEW_COUNT = 'view_count',
        ITEMS_PER_PAGE = 'items_per_page',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::CATEGORY_ID,
        self::NAME,
        self::SLUG,
        self::DESCRIPTION,
        self::SKU,
        self::IS_ACTIVE,
        self::IS_FEATURED,
        self::VIEW_COUNT,
        self::ITEMS_PER_PAGE,
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, self::CATEGORY_ID);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(ProductVideo::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function orderUnits(): BelongsToMany
    {
        return $this->belongsToMany(
            OrderUnit::class,
            'product_order_units',
            'product_id',
            'order_unit_id'
        )
        ->withPivot('max_quantity');
    }

    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(Offer::class, 'product_offers')
            ->withPivot('associated_at');
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

    public function getCreatedAtAttribute()
    {
        $this->appends[] = "created_at_p";
        $this->appends[] = "created_at_diff";
        return $this->attributes[self::CREATED_AT];
    }

    public function getUpdatedAtAttribute()
    {
        $this->appends[] = "updated_at_p";
        $this->appends[] = "updated_at_diff";
        return $this->attributes[self::UPDATED_AT];
    }
}
