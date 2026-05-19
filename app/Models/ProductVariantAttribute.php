<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

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
