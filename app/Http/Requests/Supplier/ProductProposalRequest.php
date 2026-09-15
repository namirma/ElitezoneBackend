<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier;

use App\Http\Requests\Product\ProductSpecRules;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * پیشنهادِ ساخت یا ویرایشِ محصول از سوی تأمین‌کننده.
 *
 * همان فیلدهای فرمِ ادمین (`ProductSpecRules::product`) به‌جز دو فلگِ وضعیت:
 * `is_active`/`is_featured` تصمیمِ بازاریابی/انتشارِ ادمین‌اند، نه مشخصاتِ کالا.
 * موجودیِ سراسریِ واریانت هم حذف شده — آن یک rollupِ نمایشی از
 * `factory_stocks` است و تأمین‌کننده موجودیِ خودش را از صفحه‌ی موجودی می‌نویسد.
 *
 * ⚠️ هر قاعده‌ی تودرتو یک قاعده‌ی والد دارد (`variants`, `variants.*`,
 * `order_units`, …) — بدونِ آن، `validated()` کلید را برنمی‌گرداند و مسیر به‌جای
 * ۴۲۲ به ۵۰۰ می‌رسد (همان الگویی که `ValidationNeverFiveHundredTest` می‌گیرد).
 */
class ProductProposalRequest extends FormRequest
{
    /** فیلدهایی که فقط ادمین تعیین می‌کند. */
    public const ADMIN_ONLY_PRODUCT = [Product::IS_ACTIVE, Product::IS_FEATURED];

    public const ADMIN_ONLY_VARIANT = [ProductVariant::IS_ACTIVE, ProductVariant::STOCK_QUANTITY];

    public const MAX_VARIANTS = 50;

    public const MAX_IMAGES = 10;

    public const MAX_VIDEOS = 3;

    public function authorize(): bool
    {
        return true;
    }

    public function isUpdate(): bool
    {
        return $this->route('product') instanceof Product;
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $isUpdate = $product instanceof Product;

        $rules = Arr::except(
            ProductSpecRules::product($isUpdate ? $product->id : null, partial: $isUpdate),
            self::ADMIN_ONLY_PRODUCT,
        );

        $rules += ProductSpecRules::orderUnits('order_units', partial: true);

        $rules += [
            'images' => ['sometimes', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ProductSpecRules::imageFile(),
            'primary_image_index' => ['nullable', 'integer', 'min:0'],
            'videos' => ['sometimes', 'array', 'max:'.self::MAX_VIDEOS],
            'videos.*' => ProductSpecRules::videoFile(),
            'video_titles' => ['sometimes', 'array'],
            'video_titles.*' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];

        if ($isUpdate) {
            $rules += [
                'remove_image_ids' => ['sometimes', 'array'],
                'remove_image_ids.*' => ['integer', 'exists:product_images,id,product_id,'.$product->id],
                'remove_video_ids' => ['sometimes', 'array'],
                'remove_video_ids.*' => ['integer', 'exists:product_videos,id,product_id,'.$product->id],
            ];
        } else {
            $rules += [
                'variants' => ['sometimes', 'array', 'max:'.self::MAX_VARIANTS],
                'variants.*' => ['array'],
            ];
            $rules += Arr::except(
                ProductSpecRules::variant(null, false, 'variants.*.'),
                array_map(fn (string $f) => 'variants.*.'.$f, self::ADMIN_ONLY_VARIANT),
            );
            $rules['variants.*.'.ProductVariant::SKU][] = 'distinct';
            $rules += ProductSpecRules::attributes('variants.*.attributes', partial: true);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'variants.max' => __('product_changes.too_many_variants', ['max' => self::MAX_VARIANTS]),
            'variants.*.sku.distinct' => __('product_changes.duplicate_variant_sku'),
            'images.max' => __('product_changes.too_many_images', ['max' => self::MAX_IMAGES]),
            'videos.max' => __('product_changes.too_many_videos', ['max' => self::MAX_VIDEOS]),
        ];
    }

    public function attributes(): array
    {
        return [
            'variants.*.name' => __('product_changes.fields.variant_name'),
            'variants.*.sku' => __('product_changes.fields.variant_sku'),
            'variants.*.attributes.*.attribute_id' => __('product_changes.fields.attribute'),
            'variants.*.attributes.*.attribute_value_id' => __('product_changes.fields.attribute_value'),
            'order_units.*.order_unit_id' => __('product_changes.fields.order_unit'),
            'order_units.*.max_quantity' => __('product_changes.fields.max_quantity'),
            'images.*' => __('product_changes.fields.image'),
            'videos.*' => __('product_changes.fields.video'),
        ];
    }
}
