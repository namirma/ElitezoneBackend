<?php

namespace App\Repositories\Eloquent;

use App\DataTransferObjects\ProductFilterData;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPriceCache;
use App\Models\ProductVariantAttribute;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProductRepository implements ProductRepositoryInterface
{
    /**
     * دریافت لیست محصولات با استفاده از DTO
     */
    public function getBaseQuery(ProductFilterData $filters): Builder
    {
        $query = Product::query()->active()
            ->with([
                'category',
                'images' => fn($q) => $q->where(ProductImage::IS_PRIMARY, true),
                'variants.prices.factory', // برای محاسبه قیمت نیاز است
                'variants.prices.factoryLocation',
            ]);

        // اعمال فیلتر دسته‌بندی
        if ($filters->categoryId) {
            $query->where(Product::CATEGORY_ID, $filters->categoryId);
        }

        // اعمال جستجو
        if ($filters->search) {
            $search = $filters->search;
            $query->where(function ($q) use ($search) {
                $q->where(Product::NAME, 'like', "%{$search}%")
                  ->orWhere(Product::DESCRIPTION, 'like', "%{$search}%");
            });
        }

        return $query;
    }

    public function getProducts(ProductFilterData $filters, int $perPage = 12): LengthAwarePaginator
    {
        $query = Product::query()->active()
            ->with([
                'category',
                'images' => fn($q) => $q->where(ProductImage::IS_PRIMARY, true),
            ]);

        // 1. اعمال فیلتر دسته‌بندی (استفاده از پراپرتی DTO)
        if ($filters->categoryId) {
            $query->where(Product::CATEGORY_ID, $filters->categoryId);
        }

        // 2. اعمال جستجو
        if ($filters->search) {
            $search = $filters->search;
            $query->where(function ($q) use ($search) {
                $q->where(Product::NAME, 'like', "%{$search}%")
                  ->orWhere(Product::DESCRIPTION, 'like', "%{$search}%");
            });
        }

        // 3. مرتب‌سازی پیشرفته (با استفاده از جدول کش قیمت)
        $sortKey = $filters->sortKey;
        $roleCode = $filters->roleCode;

        if (in_array($sortKey, ['cheapest', 'most_expensive'])) {
            $query->select('products.*');

            // ساب‌کوئری برای پیدا کردن قیمت نهایی از جدول کش
            $priceSubQuery = ProductPriceCache::select(ProductPriceCache::FINAL_PRICE)
                ->whereColumn(ProductPriceCache::PRODUCT_ID, 'products.id')
                ->where(ProductPriceCache::ROLE_CODE, $roleCode)
                ->orderBy(ProductPriceCache::FINAL_PRICE, 'asc')
                ->limit(1);

            $query->orderBy(
                $priceSubQuery,
                $sortKey === 'cheapest' ? 'asc' : 'desc'
            );
        } else {
            // مرتب‌سازی‌های معمولی
            switch ($sortKey) {
                case 'most_viewed':
                    $query->orderByDesc(Product::VIEW_COUNT);
                    break;
                case 'newest':
                default:
                    $query->latest(Product::CREATED_AT);
                    break;
            }
        }

        // بازگرداندن صفحه بندی شده
        return $query->paginate($perPage);
    }

    public function findProductWithDetails(string $cuid): Product
    {
        $product = Product::where(Product::CUID, $cuid)->firstOrFail();

        $product->loadMissing([
            'category',
            'images',
            'videos',
            'variants.variantAttributes.attributeType',
            'variants.limitOrderUnit',
            'variants.product.orderUnits',
            'variants.prices.factory',
            'variants.prices.factoryLocation',
            'orderUnits',
            'offers',
            'aggregateLimitOrderUnit',
        ]);

        return $product;
    }

    public function getProductVariants(Product $product, array $filters, int $perPage = 10): LengthAwarePaginator
    {
        $query = $product->variants()
            ->with(['prices.factory', 'prices.factoryLocation', 'variantAttributes.attributeType', 'cartItems', 'limitOrderUnit']);

        if (!empty($filters['filter_categories'])) {
            $query->whereHas('variantAttributes', function ($qq) use ($filters) {
                $qq->whereIn(ProductVariantAttribute::ATTRIBUTE_ID, $filters['filter_categories']);
            });
        }

        return $query->paginate($perPage);
    }

    public function getProductFactories(int $productId): Collection
    {
        return \App\Models\Factory::query()
            ->whereHas('prices.productVariant', fn($q) => $q->where(\App\Models\ProductVariant::PRODUCT_ID, $productId))
            ->with('locations')
            ->get();
    }
}
