<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\Attribute;
use App\Models\ProductVariantAttribute;
use Illuminate\Http\Request;

/**
 * @mixin ProductVariantAttribute
 */
class ProductVariantAttributeResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $pivot = $this->pivot ?? $this->details ?? null;

        if (! $pivot) {
            return [];
        }
        $attributeValue = $pivot->attributeValue;

        // 3. لود کردن گروه ویژگی
        $this->loadMissing('attributeType');

        return [
            ProductVariantAttribute::PRODUCT_VARIANT_ID => $pivot->product_variant_id,
            ProductVariantAttribute::ATTRIBUTE_ID => $pivot->attribute_id,
            // شناسه‌ی مقدار، تا فرمِ ویرایشِ واریانت (پنلِ تأمین‌کننده) بتواند بدونِ
            // تطبیقِ متنیِ `value` با فهرستِ مقادیر، انتخابِ فعلی را پیش‌پر کند.
            'attribute_value_id' => $pivot->attribute_value_id,

            'name' => $this->name,
            'label' => $this->display_name,

            // نمایش مقدار (مثلاً "14")
            'value' => $attributeValue ? $attributeValue->value : ($this->display_name ?? $this->name),
            'display_value' => $attributeValue ? $attributeValue->display_value : ($this->display_name ?? $this->name),

            'attribute_type' => [
                Attribute::ATTRIBUTE_TYPE_ID => $this->attributeType->id,
                Attribute::NAME => $this->attributeType->name,
                Attribute::DISPLAY_NAME => $this->attributeType->display_name, // اینجا "مشخصات فنی" است
            ],
        ];
    }
}
