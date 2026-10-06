<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Http\Resources\Supplier\FactoryFeatureResource;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

/**
 * @mixin ProductVariant
 */
class ProductVariantResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductVariant::ID => $this->getKey(),
            ProductVariant::PRODUCT_ID => (string) $this->{ProductVariant::PRODUCT_ID},
            ProductVariant::NAME => $this->{ProductVariant::NAME},
            ProductVariant::SKU => $this->{ProductVariant::SKU},
            ProductVariant::STOCK_QUANTITY => (string) $this->{ProductVariant::STOCK_QUANTITY},
            ProductVariant::VIEW_COUNT => (string) $this->{ProductVariant::VIEW_COUNT},

            // Purchase limits — so the frontend can validate min/max before submit
            // instead of only discovering them via a 422 at add-to-cart time.
            ProductVariant::MIN_ORDER_VALUE => $this->whenNotNull($this->{ProductVariant::MIN_ORDER_VALUE}),
            ProductVariant::MAX_ORDER_VALUE => $this->whenNotNull($this->{ProductVariant::MAX_ORDER_VALUE}),
            ProductVariant::LIMIT_ORDER_UNIT_ID => $this->{ProductVariant::LIMIT_ORDER_UNIT_ID},
            'limit_order_unit' => $this->whenLoaded('limitOrderUnit', fn () => $this->limitOrderUnit ? [
                'id' => $this->limitOrderUnit->id,
                'name' => $this->limitOrderUnit->name,
                'amount' => (string) $this->limitOrderUnit->amount,
            ] : null),
            ProductVariant::DISCOUNT_VALUE => $this->whenNotNull($this->{ProductVariant::DISCOUNT_VALUE}),
            ProductVariant::DISCOUNT_TYPE => $this->whenNotNull($this->{ProductVariant::DISCOUNT_TYPE}),
            ProductVariant::DISCOUNT_START_DATE => optional($this->{ProductVariant::DISCOUNT_START_DATE})->format('Y-m-d H:i:s'),
            ProductVariant::DISCOUNT_END_DATE => optional($this->{ProductVariant::DISCOUNT_END_DATE})->format('Y-m-d H:i:s'),
            // تخفیفِ واریانت از دورِ پنجم اعمال نمی‌شود (`features.variant_discounts`)؛ نشان‌دادنش
            // وعده‌ای بود که قیمت‌گذاری نگه نمی‌داشت (قاعده‌ی صداقت).
            'active_discount_details' => config('features.variant_discounts') ? $this->getActiveDiscountDetails() : null,
            'min_price' => isset($this->min_price) ? (string) $this->min_price : null,
            // فقط `showVariant` این را ست می‌کند (دیپ‌لینک به واریانتِ در انتظارِ
            // تأیید قیمت)؛ فهرست‌ها چنین واریانتی را اصلاً برنمی‌گردانند.
            'price_under_review' => (bool) ($this->price_under_review ?? false),
            'attributes' => ProductVariantAttributeResource::collection(
                $this->whenLoaded('variantAttributes', $this->variantAttributes)
            ),
            // تصویرِ واریانت = تصاویرِ محصولش (`product_images` ستونِ واریانت ندارد).
            // تا ۲۶ شهریور هیچ فهرستی این کلید را نمی‌داد و کارتِ سایت عکسِ آزمایشی
            // نشان می‌داد؛ حالا کارت عکسِ واقعی دارد و محصولِ بی‌عکس کارتِ بی‌عکس.
            'images' => $this->whenLoaded('product', fn () => ProductImageResource::collection($this->product->images)),
            'prices' => $this->whenLoaded('prices', function () use ($request) {
                $withDebug = $request->boolean('debug_prices');

                return $this->prices->map(function ($price) use ($withDebug) {
                    $final = isset($price->final_price)
                        ? (float) $price->final_price
                        : (float) ($price->special_price ?: $price->general_price);

                    $row = [
                        'price_id' => (string) $price->id,

                        'factory' => [
                            'id' => (string) $price->factory->id,
                            'name' => $price->factory->name,
                            // فقط ویژگی‌های «محصول» — کارتِ محصول هرگز ویژگیِ حمل را نشان نمی‌دهد.
                            'product_features' => $price->factory->relationLoaded('activeProductFeatures')
                                ? FactoryFeatureResource::collection($price->factory->activeProductFeatures)
                                : [],
                        ],
                        'factory_location' => [
                            'id' => (string) $price->factoryLocation->id,
                            'name' => $price->factoryLocation->name,
                        ],
                        'general_price' => (string) $price->general_price,
                        'special_price' => (string) $price->special_price,
                        'golden_figure' => (string) ($price->golden_figure ?? 0),
                        'final_price' => (string) $final,

                        // مالیات — از `VariantPriceHydrator` می‌آید (نه محاسبه‌ی اینجا).
                        // بدون این‌ها، باکس خریدِ سایت مجبور بود نرخ را `× ۱٫۱۰`
                        // هاردکد کند و با تغییر نرخ در `price_settings`، پیش‌نمایشِ
                        // قیمت از مبلغی که واقعاً در سبد کسر می‌شود واگرا می‌شد.
                        'vat_rate' => isset($price->vat_rate) ? (float) $price->vat_rate : null,
                        'vat_amount' => isset($price->vat_amount) ? (string) $price->vat_amount : null,
                        'total_with_vat' => isset($price->price_with_vat) ? (string) $price->price_with_vat : null,

                        'role_special_price' => isset($price->role_special_price) ? (string) $price->role_special_price : null,
                        'role_general_price' => isset($price->role_general_price) ? (string) $price->role_general_price : null,
                        'role_golden_figure' => isset($price->role_golden_figure) ? (string) $price->role_golden_figure : null,
                        // درصدِ «تخفیف» برای کارتِ محصول (فقط گروهِ دارای الیت‌یار، وگرنه "0") —
                        // مدیریت، دورِ پنجم، پرسشِ ۱. قیمتِ پایه خودش تخفیف نخورده؛ تخفیف سطرِ جدای سفارش است.
                        'buyer_discount_percent' => isset($price->buyer_discount_percent) ? (string) $price->buyer_discount_percent : null,
                    ];

                    if ($withDebug && isset($price->pricing_debug) && is_array($price->pricing_debug)) {
                        $row['pricing_debug'] = $price->pricing_debug;
                    }

                    return $row;
                })->values();
            }),
            ProductVariant::CREATED_AT => optional($this->{ProductVariant::CREATED_AT})->format('Y-m-d H:i:s'),
            ProductVariant::UPDATED_AT => optional($this->{ProductVariant::UPDATED_AT})->format('Y-m-d H:i:s'),
        ];
    }
}
