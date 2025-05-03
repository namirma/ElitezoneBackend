<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantAttribute extends Model
{
    use HasFactory;

    const
        ID = 'id',
        PRODUCT_VARIANT_ID = 'product_variant_id',
        ATTRIBUTE_ID = 'attribute_id',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_VARIANT_ID,
        self::ATTRIBUTE_ID,
    ];

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function attributeValue()
    {
        return $this->belongsTo(AttributeValue::class, 'attribute_value_id');
    }
}
