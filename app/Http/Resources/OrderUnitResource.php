<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\OrderUnit;

class OrderUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            OrderUnit::ID         => $this->id,
            OrderUnit::NAME       => $this->name,
            OrderUnit::CODE       => $this->code,
            OrderUnit::UNIT       => $this->unit,
            OrderUnit::AMOUNT     => $this->amount,
            OrderUnit::CREATED_AT => $this->created_at?->format('Y-m-d H:i:s'),
            OrderUnit::UPDATED_AT => $this->updated_at?->format('Y-m-d H:i:s'),

            // فقط اگر رابطه‌ی pivot لود شده بود و فیلد زمان داشت، برگردان
            'pivot' => $this->whenPivotLoaded('product_order_unit', function () {
                return [
                    'product_id'    => $this->pivot->product_id,
                    'order_unit_id' => $this->pivot->order_unit_id,
                    'max_quantity'  => $this->pivot->max_quantity,
                    'created_at' => optional($this->pivot->created_at)->format('Y-m-d H:i:s'),
                    'updated_at' => optional($this->pivot->updated_at)->format('Y-m-d H:i:s'),
                ];
            }),
        ];
    }
}
