<?php

namespace App\Http\Resources;

use App\Models\OrderUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            OrderUnit::ID => $this->id,
            OrderUnit::NAME => $this->name,
            OrderUnit::CODE => $this->code,
            OrderUnit::UNIT => $this->unit,
            OrderUnit::AMOUNT => $this->amount,
            OrderUnit::CREATED_AT => $this->created_at?->format('Y-m-d H:i:s'),
            OrderUnit::UPDATED_AT => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
