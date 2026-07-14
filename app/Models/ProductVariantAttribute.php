<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $product_variant_id
 * @property int $attribute_id
 * @property int $attribute_value_id
 * @property-read \App\Models\Attribute $attribute
 * @property-read \App\Models\AttributeValue $attributeValue
 * @property-read \App\Models\ProductVariant $productVariant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute whereAttributeValueId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute whereProductVariantId($value)
 * @mixin \Eloquent
 */
class ProductVariantAttribute extends Pivot
{
    use HasFactory;

    protected $table = 'product_variant_attributes';

    public $timestamps = false;

    // تعریف نام ستون‌ها به عنوان ثابت (اختیاری ولی خوب است)
    const ID = 'id';

    const PRODUCT_VARIANT_ID = 'product_variant_id';

    const ATTRIBUTE_ID = 'attribute_id';

    const ATTRIBUTE_VALUE_ID = 'attribute_value_id';

    protected $fillable = [
        self::PRODUCT_VARIANT_ID,
        self::ATTRIBUTE_ID,
        self::ATTRIBUTE_VALUE_ID,
    ];

    /**
     * ارتباط با واریانت محصول
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }

    /**
     * ارتباط با نوع ویژگی (مثلاً رنگ)
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, self::ATTRIBUTE_ID);
    }

    /**
     * ارتباط با مقدار ویژگی (مثلاً قرمز)
     */
    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class, self::ATTRIBUTE_VALUE_ID);
    }
}
