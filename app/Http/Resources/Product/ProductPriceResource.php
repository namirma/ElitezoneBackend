<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\ProductPrice;
use Illuminate\Http\Request;

/**
 * @mixin ProductPrice
 */
class ProductPriceResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductPrice::ID => $this->id,
            ProductPrice::PRODUCT_VARIANT_ID => $this->product_variant_id,
            ProductPrice::FACTORY_ID => $this->factory_id,
            ProductPrice::FACTORY_LOCATION_ID => $this->factory_location_id,
            ProductPrice::GENERAL_PRICE => (string) $this->general_price,
            ProductPrice::SPECIAL_PRICE => (string) $this->special_price,
            ProductPrice::GOLDEN_FIGURE => (string) $this->golden_figure,
            'factory' => $this->whenLoaded('factory', fn () => [
                'id' => $this->factory->id,
                'name' => $this->factory->name,
            ]),
            'factory_location' => $this->whenLoaded('factoryLocation', fn () => [
                'id' => $this->factoryLocation->id,
                'name' => $this->factoryLocation->name,
            ]),
            ProductPrice::LAST_UPDATED_AT => optional($this->last_updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
