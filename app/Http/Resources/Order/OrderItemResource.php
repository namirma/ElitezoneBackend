<?php

namespace App\Http\Resources\Order;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;
use App\Http\Resources\Product\ProductVariantResource;
use App\Http\Resources\Profile\UserResource;

/**
 * @mixin \App\Models\OrderItem
 */
class OrderItemResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            OrderItem::ID => $this->id,
            OrderItem::ORDER_ID => $this->order_id,
            OrderItem::PRODUCT_VARIANT_ID => $this->product_variant_id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier_user_id,
            OrderItem::PRODUCT_NAME => $this->product_name,
            OrderItem::VARIANT_SKU => $this->variant_sku,
            OrderItem::QUANTITY_DETAILS => $this->quantity_details,
            OrderItem::UNIT_PRICE => (string) $this->unit_price,
            OrderItem::TOTAL_PRICE => (string) $this->total_price,
            OrderItem::CREATED_AT => $this->created_at?->format('Y-m-d H:i:s'),
            'variant' => new ProductVariantResource($this->whenLoaded('productVariant')),
            'supplier' => new UserResource($this->whenLoaded('supplier')),
        ];
    }
}
