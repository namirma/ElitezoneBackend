<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\ProductPrice;
use App\Models\SupplierRequest;
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
            'variant' => $this->whenLoaded('productVariant', fn () => [
                'id' => $this->productVariant->id,
                'name' => $this->productVariant->name,
            ]),
            'factory' => $this->whenLoaded('factory', fn () => [
                'id' => $this->factory->id,
                'name' => $this->factory->name,
            ]),
            'factory_location' => $this->whenLoaded('factoryLocation', fn () => [
                'id' => $this->factoryLocation->id,
                'name' => $this->factoryLocation->name,
            ]),
            ProductPrice::LAST_UPDATED_AT => optional($this->last_updated_at)->format('Y-m-d H:i:s'),
            // درخواستِ تغییرِ قیمتِ باز روی این ردیف — null یعنی هیچ صفی در کار
            // نیست. فقط وقتی می‌آید که کنترلر رابطه را لود کرده باشد (پنلِ تأمین‌کننده).
            'pending_change' => $this->whenLoaded('pendingPriceChange', fn () => $this->pendingPriceChange ? [
                'id' => $this->pendingPriceChange->getKey(),
                'proposed_general_price' => $this->pendingPriceChange->payload['proposed_general_price'] ?? null,
                'proposed_special_price' => $this->pendingPriceChange->payload['proposed_special_price'] ?? null,
                'proposed_golden_figure' => $this->pendingPriceChange->payload['proposed_golden_figure'] ?? null,
                'change_percent' => $this->pendingPriceChange->payload['change_percent'] ?? null,
                'review_deadline' => $this->pendingPriceChange->{SupplierRequest::REVIEW_DEADLINE}?->toIso8601String(),
            ] : null),
        ];
    }
}
