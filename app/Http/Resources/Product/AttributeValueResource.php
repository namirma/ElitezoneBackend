<?php

namespace App\Http\Resources\Product;

use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

class AttributeValueResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'value' => $this->value,
        ];
    }
}
