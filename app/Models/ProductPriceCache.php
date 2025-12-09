<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceCache extends Model
{
    use HasFactory;
    
    public const
        ID = 'id',
        PRODUCT_ID = 'product_id',
        PRODUCT_VARIANT_ID = 'product_variant_id',
        ROLE_CODE = 'role_code',
        FINAL_PRICE = 'final_price',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_ID,
        self::PRODUCT_VARIANT_ID,
        self::ROLE_CODE,
        self::FINAL_PRICE,
    ];

    protected $casts = [
        self::FINAL_PRICE => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, self::PRODUCT_ID);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }
}
