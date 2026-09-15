<?php

declare(strict_types=1);

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\ProductChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * یک درخواستِ تغییرِ کاتالوگ — همان شکل برای تأمین‌کننده و ادمین؛ بلوکِ
 * `supplier` فقط وقتی می‌آید که رابطه لود شده باشد (فهرستِ ادمین).
 *
 * `media` نسخه‌ی URL‌دارِ فایل‌های payload است تا هیچ کلاینتی مجبور نباشد
 * مسیرِ دیسک را خودش به نشانی تبدیل کند.
 *
 * @mixin ProductChangeRequest
 */
class ProductChangeRequestResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $payload = $this->{ProductChangeRequest::PAYLOAD} ?? [];
        $url = fn (string $path) => Storage::disk('public')->url($path);

        return [
            ProductChangeRequest::ID => $this->getKey(),
            ProductChangeRequest::TYPE => $this->{ProductChangeRequest::TYPE},
            ProductChangeRequest::STATUS => $this->{ProductChangeRequest::STATUS},
            ProductChangeRequest::PRODUCT_ID => $this->{ProductChangeRequest::PRODUCT_ID},
            ProductChangeRequest::PRODUCT_VARIANT_ID => $this->{ProductChangeRequest::PRODUCT_VARIANT_ID},
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'cuid' => $this->product->cuid,
                'name' => $this->product->name,
            ] : null),
            'variant' => $this->whenLoaded('variant', fn () => $this->variant ? [
                'id' => $this->variant->id,
                'name' => $this->variant->name,
                'sku' => $this->variant->sku,
            ] : null),
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'user_id' => $this->{ProductChangeRequest::SUPPLIER_USER_ID},
                'name' => $this->supplier?->profile?->name,
                'mobile' => $this->supplier?->mobile,
            ]),
            // عنوانِ خوانا برای ستونِ «موضوع»: نامِ رکوردِ هدف، یا نامِ پیشنهادی.
            'subject' => $this->variant?->name ?? $this->product?->name ?? ($payload['name'] ?? null),
            ProductChangeRequest::PAYLOAD => $payload,
            ProductChangeRequest::DIFF => $this->{ProductChangeRequest::DIFF},
            'media' => [
                'images' => array_map(fn (array $i) => ['url' => $url($i['path']), 'is_primary' => (bool) ($i['is_primary'] ?? false)], $payload['images'] ?? []),
                'videos' => array_map(fn (array $v) => ['url' => $url($v['path']), 'title' => $v['title'] ?? null], $payload['videos'] ?? []),
            ],
            ProductChangeRequest::NOTE => $this->{ProductChangeRequest::NOTE},
            ProductChangeRequest::ADMIN_NOTE => $this->{ProductChangeRequest::ADMIN_NOTE},
            ProductChangeRequest::DECIDED_AT => optional($this->{ProductChangeRequest::DECIDED_AT})->format('Y-m-d H:i:s'),
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
        ];
    }
}
