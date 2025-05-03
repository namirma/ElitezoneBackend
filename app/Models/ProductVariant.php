<?php

namespace App\Models;

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
        IMAGES = 'images',
        VIDEOS = 'videos',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_ID,
        self::NAME,
        self::SKU,
        self::IS_ACTIVE,
        self::STOCK_QUANTITY,
        self::IMAGES,
        self::VIDEOS,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
        self::STOCK_QUANTITY => 'integer',
        self::IMAGES => 'array',
        self::VIDEOS => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variantAttributes(): BelongsToMany
    {
        return $this->belongsToMany(
            Attribute::class,
            'product_variant_attributes',
            'product_variant_id',
            'attribute_id'
        )
        ->withPivot(['created_at', 'updated_at'])
        ->with('attributeType');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(\App\Models\CartItem::class, 'product_variant_id');
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }


}
