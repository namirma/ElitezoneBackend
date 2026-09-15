<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin ProductCategory
 */
class ProductCategoryResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductCategory::ID => $this->id,
            ProductCategory::NAME => $this->name,
            ProductCategory::SLUG => $this->slug,
            ProductCategory::SUBTITLE => $this->subtitle,
            ProductCategory::DESCRIPTION => $this->description,
            // URL کامل، همان قراردادِ BannerResource — سایت فقط `/api` را پراکسی
            // می‌کند، پس مسیرِ خامِ `categories/x.jpg` روی صفحه‌ی دسته‌بندی ۴۰۴ می‌شد.
            ProductCategory::IMAGE => $this->image
                ? (str_starts_with($this->image, 'http') ? $this->image : Storage::disk('public')->url($this->image))
                : null,
            ProductCategory::IS_ACTIVE => $this->is_active,
            'products_count' => $this->whenCounted('products'),
            // انواعِ ویژگیِ این دسته (`category_attributes`) — فقط وقتی لود شده؛ فرمِ
            // واریانت با آن فهرستِ ویژگی‌ها را به دسته‌ی انتخاب‌شده محدود می‌کند.
            'attribute_type_ids' => $this->whenLoaded(
                'attributeTypes',
                fn () => $this->attributeTypes->pluck('id')->values()->all()
            ),
        ];
    }
}
