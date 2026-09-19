<?php

namespace App\Http\Resources\Cart;

use App\Http\Resources\BaseJsonResource;
use App\Http\Resources\Order\ShippingMethodResource;
use App\Http\Resources\Product\ProductOrderUnitResource;
use App\Http\Resources\Product\ProductVariantResource;
use App\Http\Resources\Supplier\FactoryLocationResource;
use App\Http\Resources\Supplier\FactoryResource;
use App\Models\CartItem;
use Illuminate\Support\Collection;

/**
 * @mixin CartItem
 */
class CartItemResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        // 1. شبیه‌سازی واریانت برای فیلتر کردن قیمت‌ها بر اساس کارخانه و لوکیشن آیتم
        $variant = $this->productVariant->replicate();
        $variant->setRelation(
            'prices',
            $this->productVariant->prices
                ->where('factory_id', (int) $this->factory_id)
                ->where('factory_location_id', (int) $this->factory_location_id)
        );

        // CartItem::quantities is cast to 'array' by the model, so Eloquent
        // always decodes the JSON column automatically. The previous is_string()
        // + json_decode() branch was unreachable dead code.
        $quantities = (array) ($this->quantities ?? []);

        return [
            CartItem::ID => (string) $this->id,
            CartItem::CART_ID => (string) $this->cart_id,
            'product_variant' => new ProductVariantResource($variant),

            'factory' => new FactoryResource($this->whenLoaded('factory')),
            'factory_location' => new FactoryLocationResource($this->whenLoaded('factoryLocation')),
            CartItem::ADDRESS_ID => $this->address_id,
            'shipping_method' => new ShippingMethodResource($this->whenLoaded('shippingMethod')),

            CartItem::QUANTITIES => (object) ($quantities ?? []),

            // واحدهای سفارشِ محصولِ والد — همان شکلی که `ProductResource` می‌دهد.
            // `CartResponseBuilder` رابطه‌ی `items.productVariant.product.orderUnits` را از
            // قبل eager-load می‌کرد ولی هیچ‌جا serialize نمی‌شد (یک eager-loadِ هدررفته)،
            // پس صفحه‌ی سبد نمی‌توانست باکس خرید را داده‌محور رندر کند و به همان
            // شناسه‌های هاردکدِ ۱/۲/۳ گیر کرده بود.
            'order_unit_limits' => ProductOrderUnitResource::sortedCollection($this->loadedOrderUnits()),

            CartItem::PRICE => (string) $this->price,
            CartItem::FINAL_PRICE => (string) $this->final_price,
            CartItem::VAT_AMOUNT => (string) $this->vat_amount,
            CartItem::TOTAL_WITH_VAT => (string) $this->total_with_vat,
            CartItem::WITH_VAT => (bool) $this->with_vat,

            'weight' => (string) $this->weight,
            'quantity' => (string) $this->quantity,
            'unit_name' => (string) $this->unit_name,

            CartItem::CREATED_AT => optional($this->created_at)->format('Y-m-d H:i:s'),
            CartItem::UPDATED_AT => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * واحدهای سفارشِ محصولِ والد — فقط اگر واقعاً eager-load شده باشند.
     *
     * صریح چک می‌شود تا این Resource هرگز یک کوئری N+1 راه نیندازد؛ فراخوانی از مسیری
     * که رابطه را لود نکرده، آرایه‌ی خالی می‌گیرد نه یک لود پنهان.
     */
    private function loadedOrderUnits(): ?Collection
    {
        // ‏`cart_items.product_variant_id` و `product_variants.product_id` هر دو در
        // اسکیما NOT NULL‌اند، پس هیچ‌کدام از این دو رابطه نمی‌تواند غایب باشد و
        // nullsafe اینجا هرگز کاری نمی‌کرد. لود‌نشدنِ رابطه همچنان چک می‌شود.
        $variant = $this->productVariant;
        if (! $variant->relationLoaded('product')) {
            return null;
        }

        $product = $variant->product;

        return $product->relationLoaded('orderUnits') ? $product->orderUnits : null;
    }
}
