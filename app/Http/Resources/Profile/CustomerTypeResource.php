<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\BaseJsonResource;
use App\Models\CustomerType;

/**
 * @mixin \App\Models\CustomerType
 */
class CustomerTypeResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        return [
            CustomerType::ID => $this->id,
            CustomerType::NAME => $this->name,
            CustomerType::CODE => $this->code,
            CustomerType::HAS_ELITE_YAR => $this->has_elite_yar,
            CustomerType::CREATED_AT => $this->created_at?->format('Y-m-d H:i:s'),
            CustomerType::UPDATED_AT => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
