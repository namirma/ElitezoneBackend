<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ProductVideo;
use Illuminate\Validation\Rule;

/**
 * قواعدِ اعتبارسنجیِ مشخصاتِ محصول و واریانت — **یک** تعریف برای دو صداکننده.
 *
 * تا ۲۰۲۶-۰۹-۱۴ این قواعد داخلِ `AdminProductController::rules()` و
 * `AdminProductVariantController::rules()` بودند. پنلِ تأمین‌کننده همان فرم را
 * فیلد‌به‌فیلد پیشنهاد می‌دهد و پذیرشِ ادمین همان payload را می‌نویسد؛ اگر
 * قاعده دو کپی داشت، چیزی که تأمین‌کننده می‌توانست بفرستد با چیزی که کاتالوگ
 * می‌پذیرد جدا می‌شد و در لحظه‌ی تأیید ۵۰۰ می‌داد.
 *
 * `$prefix` برای قواعدِ تودرتو است (`variants.*.`)؛ `$partial` یعنی ویرایش
 * (`sometimes` به‌جای `required`).
 */
final class ProductSpecRules
{
    /** @return array<string, array<int, mixed>> */
    public static function product(?int $ignoreId = null, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            Product::CATEGORY_ID => [$req, 'integer', 'exists:product_categories,id'],
            Product::NAME => [$req, 'string', 'max:255'],
            Product::SLUG => [$req, 'string', 'max:255', Rule::unique('products', 'slug')->ignore($ignoreId)],
            Product::SKU => [$req, 'string', 'max:255', Rule::unique('products', 'sku')->ignore($ignoreId)],
            Product::DESCRIPTION => ['nullable', 'string'],
            Product::SHIPPING_PROMO => ['nullable', 'string', 'max:255'],
            Product::IS_ACTIVE => ['boolean'],
            Product::IS_FEATURED => ['boolean'],
            Product::ITEMS_PER_PAGE => ['nullable', 'integer', 'min:1', 'max:100'],
            Product::AGGREGATE_MIN_ORDER_VALUE => ['nullable', 'numeric', 'min:0'],
            Product::AGGREGATE_MAX_ORDER_VALUE => ['nullable', 'numeric', 'min:0'],
            Product::AGGREGATE_LIMIT_ORDER_UNIT_ID => ['nullable', 'integer', 'exists:order_units,id'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function variant(?int $ignoreId = null, bool $partial = false, string $prefix = ''): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            $prefix.ProductVariant::NAME => [$req, 'string', 'max:255'],
            $prefix.ProductVariant::SKU => [$req, 'string', 'max:255', Rule::unique('product_variants', 'sku')->ignore($ignoreId)],
            $prefix.ProductVariant::IS_ACTIVE => ['boolean'],
            $prefix.ProductVariant::STOCK_QUANTITY => ['nullable', 'integer', 'min:0'],
            $prefix.ProductVariant::MIN_ORDER_VALUE => ['nullable', 'numeric', 'min:0'],
            $prefix.ProductVariant::MAX_ORDER_VALUE => ['nullable', 'numeric', 'min:0'],
            $prefix.ProductVariant::LIMIT_ORDER_UNIT_ID => ['nullable', 'integer', 'exists:order_units,id'],
            $prefix.ProductVariant::DISCOUNT_VALUE => ['nullable', 'numeric', 'min:0'],
            $prefix.ProductVariant::DISCOUNT_TYPE => ['nullable', Rule::in(['percentage', 'fixed_amount'])],
            $prefix.ProductVariant::DISCOUNT_START_DATE => ['nullable', 'date'],
            $prefix.ProductVariant::DISCOUNT_END_DATE => ['nullable', 'date', 'after_or_equal:'.$prefix.ProductVariant::DISCOUNT_START_DATE],
        ];
    }

    /**
     * واحدهای سفارشِ مجاز + سقفِ هرکدام (`product_order_units`).
     *
     * `present` نه `required`: آرایه‌ی خالی معتبر است و یعنی «هیچ واحدی».
     *
     * @return array<string, array<int, mixed>>
     */
    public static function orderUnits(string $key = 'units', bool $partial = false): array
    {
        return [
            $key => [$partial ? 'sometimes' : 'present', 'array'],
            $key.'.*' => ['array'],
            $key.'.*.order_unit_id' => ['required', 'integer', 'exists:order_units,id'],
            $key.'.*.max_quantity' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function attributes(string $key = 'attributes', bool $partial = false): array
    {
        return [
            $key => [$partial ? 'sometimes' : 'present', 'array'],
            $key.'.*' => ['array'],
            $key.'.*.attribute_id' => ['required', 'integer', 'exists:attributes,id'],
            $key.'.*.attribute_value_id' => ['required', 'integer', 'exists:attribute_values,id'],
        ];
    }

    /** @return array<int, string> */
    public static function imageFile(): array
    {
        return ['file', 'mimes:png,jpg,jpeg,webp', 'max:4096'];
    }

    /** @return array<int, string> */
    public static function videoFile(): array
    {
        return ['file', 'mimes:mp4,webm,mov,ogg', 'max:51200'];
    }

    /** @return array<string, array<int, mixed>> */
    public static function imageUpload(): array
    {
        return [
            ProductImage::IMAGE_PATH => ['required', ...self::imageFile()],
            ProductImage::IS_PRIMARY => ['boolean'],
            ProductImage::SORT_ORDER => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function videoUpload(): array
    {
        return [
            ProductVideo::VIDEO_PATH => ['required', ...self::videoFile()],
            ProductVideo::THUMBNAIL_PATH => ['nullable', ...self::imageFile()],
            ProductVideo::TITLE => ['nullable', 'string', 'max:255'],
            ProductVideo::SORT_ORDER => ['nullable', 'integer', 'min:0'],
        ];
    }
}
