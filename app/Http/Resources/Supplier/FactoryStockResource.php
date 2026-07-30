<?php

namespace App\Http\Resources\Supplier;

use App\Http\Resources\BaseJsonResource;
use App\Models\FactoryStock;
use Hekmatinasser\Verta\Verta;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\FactoryStock
 */
class FactoryStockResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            FactoryStock::ID => $this->id,
            FactoryStock::PRODUCT_VARIANT_ID => (int) $this->{FactoryStock::PRODUCT_VARIANT_ID},
            FactoryStock::FACTORY_ID => (int) $this->{FactoryStock::FACTORY_ID},
            FactoryStock::FACTORY_LOCATION_ID => (int) $this->{FactoryStock::FACTORY_LOCATION_ID},

            // (float) عمدی — ستون decimal است و لاراول آن را رشته سریالایز می‌کند،
            // در حالی که فرانت رویش حساب می‌کند. همان شکلی که باگ ۱۰۰ برابری
            // را در order_units ساخته بود.
            FactoryStock::QUANTITY => (float) $this->{FactoryStock::QUANTITY},

            'variant' => $this->whenLoaded('productVariant', fn () => [
                'id' => $this->productVariant->id,
                'name' => $this->productVariant->name,
            ]),
            'factory' => $this->whenLoaded('originFactory', fn () => [
                'id' => $this->originFactory->id,
                'name' => $this->originFactory->name,
            ]),
            'location' => $this->whenLoaded('originFactoryLocation', fn () => [
                'id' => $this->originFactoryLocation->id,
                'name' => $this->originFactoryLocation->name,
            ]),

            // Verta::createTimestamp و نه helper‌ی verta(): پارامترِ helper بدون تایپ
            // و با پیش‌فرضِ null است، پس PHPStan هر آرگومانی را رد می‌کند.
            'updated_at_p' => $this->updated_at
                ? Verta::createTimestamp($this->updated_at->getTimestamp())->format('Y/m/d H:i')
                : null,
        ];
    }
}
