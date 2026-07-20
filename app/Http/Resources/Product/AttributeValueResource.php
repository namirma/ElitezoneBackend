<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\AttributeValue
 */
class AttributeValueResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attribute_id' => $this->attribute_id,
            'value' => $this->value,
            'display_value' => $this->display_value,
        ];
    }
}
