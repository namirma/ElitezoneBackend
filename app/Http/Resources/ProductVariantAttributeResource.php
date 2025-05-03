<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\ProductVariantAttribute;
use App\Models\Attribute;

class ProductVariantAttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->loadMissing('attributeType');

        return [
            ProductVariantAttribute::PRODUCT_VARIANT_ID => $this->pivot->product_variant_id,
            ProductVariantAttribute::ATTRIBUTE_ID       => $this->pivot->attribute_id,
            'value'         => $this->display_name,
            'display_value' => $this->display_name,
            'attribute_type' => [
                Attribute::ATTRIBUTE_TYPE_ID   => $this->attributeType->id,
                Attribute::NAME                => $this->attributeType->name,
                Attribute::DISPLAY_NAME        => $this->attributeType->display_name,
            ],
        ];
    }
}
