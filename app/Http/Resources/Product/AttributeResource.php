<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\Attribute;
use Illuminate\Http\Request;

/**
 * @mixin Attribute
 */
class AttributeResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Attribute::ID => $this->id,
            Attribute::ATTRIBUTE_TYPE_ID => $this->attribute_type_id,
            Attribute::NAME => $this->name,
            Attribute::DISPLAY_NAME => $this->display_name,
            'values' => AttributeValueResource::collection($this->whenLoaded('values')),
        ];
    }
}
