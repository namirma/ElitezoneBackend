<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ProductVideo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * تنها مسیرِ نوشتنِ کاتالوگ — محصول، واریانت، ویژگی‌ها، واحدها، رسانه.
 *
 * دو صداکننده دارد و باید همین دو بمانند: پنلِ ادمین (مستقیم) و پذیرشِ
 * درخواستِ تأمین‌کننده (`ProductChangeRequestService::apply`). اگر پذیرش
 * کپیِ خودش از `Product::create` را داشت، هر قاعده‌ای که بعداً به مسیرِ ادمین
 * اضافه شود (مثلاً فلاشِ کش، یا یک ستونِ مشتق) در مسیرِ دوم جا می‌ماند.
 *
 * فایل‌ها روی دیسکِ `public` و در همان پوشه‌هایی می‌نشینند که پیش از این
 * `AdminProductController` می‌نوشت (`products/`, `product-videos/`).
 */
final class ProductCatalogWriter
{
    public const IMAGE_DIR = 'products';

    public const VIDEO_DIR = 'product-videos';

    public const THUMB_DIR = 'product-videos/thumbnails';

    /** @param  array<string, mixed>  $attributes */
    public function createProduct(array $attributes): Product
    {
        return Product::create($attributes);
    }

    /** @param  array<string, mixed>  $attributes */
    public function updateProduct(Product $product, array $attributes): Product
    {
        $product->update($attributes);

        return $product;
    }

    /**
     * جایگزینیِ کامل (`sync`) — آرایه‌ی خالی یعنی «هیچ واحدی»، و محصولِ بدونِ
     * واحد قابلِ افزودن به سبد نیست (`AddToCartAction::validateOrderUnitCounts`).
     *
     * @param  array<int, array{order_unit_id: int|string, max_quantity: int|string}>  $units
     */
    public function syncOrderUnits(Product $product, array $units): void
    {
        $sync = [];
        foreach ($units as $row) {
            $sync[(int) $row['order_unit_id']] = ['max_quantity' => (int) $row['max_quantity']];
        }
        $product->orderUnits()->sync($sync);
    }

    /** @param  array<string, mixed>  $attributes */
    public function createVariant(Product $product, array $attributes): ProductVariant
    {
        $attributes[ProductVariant::PRODUCT_ID] = $product->id;

        return ProductVariant::create($attributes);
    }

    /** @param  array<string, mixed>  $attributes */
    public function updateVariant(ProductVariant $variant, array $attributes): ProductVariant
    {
        $variant->update($attributes);

        return $variant;
    }

    /**
     * جایگزینیِ کامل (`sync`) روی pivot `product_variant_attributes`.
     *
     * @param  array<int, array{attribute_id: int|string, attribute_value_id: int|string}>  $pairs
     */
    public function syncVariantAttributes(ProductVariant $variant, array $pairs): void
    {
        $sync = [];
        foreach ($pairs as $pair) {
            $sync[(int) $pair['attribute_id']] = ['attribute_value_id' => (int) $pair['attribute_value_id']];
        }
        $variant->variantAttributes()->sync($sync);
    }

    public function storeImage(Product $product, UploadedFile $file, bool $isPrimary = false, int $sortOrder = 0): ProductImage
    {
        return $this->attachImage($product, $file->store(self::IMAGE_DIR, 'public'), $isPrimary, $sortOrder);
    }

    /** `$path` از قبل روی دیسکِ `public` است (آپلودِ ادمین، یا فایلِ منتقل‌شده‌ی یک درخواست). */
    public function attachImage(Product $product, string $path, bool $isPrimary = false, int $sortOrder = 0): ProductImage
    {
        return DB::transaction(function () use ($product, $path, $isPrimary, $sortOrder) {
            if ($isPrimary) {
                $product->images()->update([ProductImage::IS_PRIMARY => false]);
            }

            return $product->images()->create([
                ProductImage::IMAGE_PATH => $path,
                ProductImage::IS_PRIMARY => $isPrimary,
                ProductImage::SORT_ORDER => $sortOrder,
            ]);
        });
    }

    public function storeVideo(
        Product $product,
        UploadedFile $video,
        ?UploadedFile $thumbnail = null,
        ?string $title = null,
        int $sortOrder = 0,
    ): ProductVideo {
        return $this->attachVideo(
            $product,
            $video->store(self::VIDEO_DIR, 'public'),
            $thumbnail?->store(self::THUMB_DIR, 'public'),
            $title,
            $sortOrder,
        );
    }

    public function attachVideo(Product $product, string $videoPath, ?string $thumbnailPath, ?string $title, int $sortOrder = 0): ProductVideo
    {
        return $product->videos()->create([
            ProductVideo::VIDEO_PATH => $videoPath,
            ProductVideo::THUMBNAIL_PATH => $thumbnailPath,
            ProductVideo::TITLE => $title,
            ProductVideo::SORT_ORDER => $sortOrder,
        ]);
    }

    public function deleteImage(ProductImage $image): void
    {
        $this->deleteFileIfOwned($image->image_path);
        $image->delete();
    }

    public function deleteVideo(ProductVideo $video): void
    {
        $this->deleteFileIfOwned($video->video_path);
        $this->deleteFileIfOwned($video->thumbnail_path);
        $video->delete();
    }

    /** فایلِ فیزیکی فقط وقتی پاک می‌شود که مالِ همین دیسک باشد (نه URL خارجی). */
    public function deleteFileIfOwned(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http') && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
