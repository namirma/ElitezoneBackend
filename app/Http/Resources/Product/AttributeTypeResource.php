<?php

namespace App\Http\Resources\Product;

use App\Models\AttributeType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttributeTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            AttributeType::ID => $this->id,
            AttributeType::NAME => $this->name,
            AttributeType::DISPLAY_NAME => $this->display_name,
            AttributeType::INPUT_TYPE => $this->input_type,
            AttributeType::CREATED_AT => $this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s') : null,
            AttributeType::UPDATED_AT => $this->updated_at ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s') : null,
            'attributes' => AttributeResource::collection($this->whenLoaded('attributes')),
        ];
    }
}
