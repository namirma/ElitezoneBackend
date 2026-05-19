<?php

namespace App\Http\Resources;

use App\Models\CustomerType;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerTypeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            CustomerType::ID => $this->id,
            CustomerType::NAME => $this->name,
            CustomerType::CODE => $this->code,
            CustomerType::HAS_CONSULTANT => $this->has_consultant,
            CustomerType::CREATED_AT => $this->created_at?->format('Y-m-d H:i:s'),
            CustomerType::UPDATED_AT => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
