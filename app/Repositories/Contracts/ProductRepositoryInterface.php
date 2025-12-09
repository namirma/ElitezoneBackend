<?php

namespace App\Repositories\Contracts;

use App\DataTransferObjects\ProductFilterData;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ProductRepositoryInterface
{
    /**
     * دریافت لیست محصولات با فیلتر، جستجو و مرتب‌سازی
     */
   public function getProducts(ProductFilterData $filters, int $perPage = 12): LengthAwarePaginator;

    /**
     * یافتن یک محصول با تمام روابط مورد نیاز برای صفحه جزئیات
     */
    public function findProductWithDetails(string $cuid): Product;

    /**
     * دریافت واریانت‌های یک محصول با فیلتر
     */
    public function getProductVariants(Product $product, array $filters, int $perPage = 10): LengthAwarePaginator;

    /**
     * دریافت کارخانه‌های مرتبط با محصول
     */
    public function getProductFactories(int $productId): Collection;
}
