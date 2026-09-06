<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\BaseJsonResource;
use App\Models\OrderUnit;
use Illuminate\Http\Request;

/**
 * @mixin OrderUnit
 */
class OrderUnitResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            OrderUnit::ID => $this->id,
            OrderUnit::NAME => $this->name,
            OrderUnit::CODE => $this->code,
            OrderUnit::UNIT => $this->unit,
            OrderUnit::AMOUNT => $this->amount,
            // فقط وقتی withCount('products') اجرا شده باشد (لیست ادمین) — به ادمین
            // می‌گوید این واحد به چند محصول وصل است، یعنی حذفش چه چیزی را می‌شکند.
            'products_count' => $this->whenCounted('products'),
            OrderUnit::CREATED_AT => $this->created_at?->format('Y-m-d H:i:s'),
            OrderUnit::UPDATED_AT => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
