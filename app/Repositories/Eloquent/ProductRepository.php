<?php

namespace App\Repositories\Eloquent;

use App\DataTransferObjects\ProductFilterData;
use App\Models\Factory;
use App\Models\Product;
use App\Models\ProductVariantAttribute;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductRepository implements ProductRepositoryInterface
{
    public function getFilterQuery(ProductFilterData $filters): Builder
    {
        return Product::query()
            ->with(['variants.prices', 'category', 'images'])
            ->active()

            // فیلتر جستجو
            ->when($filters->search, function (Builder $q) use ($filters) {
                $q->where(function ($sub) use ($filters) {
                    $sub->where('name', 'like', "%{$filters->search}%")
                        ->orWhere('description', 'like', "%{$filters->search}%");
                });
            })

            // فیلتر دسته‌بندی
            ->when($filters->categoryId, fn($q) => $q->where('category_id', $filters->categoryId))

            ->when(!empty($filters->filterCategories), function (Builder $q) use ($filters) {
                $q->whereHas('variants.variantAttributes', function ($sub) use ($filters) {
                    $sub->whereIn(ProductVariantAttribute::ATTRIBUTE_ID, $filters->filterCategories);
                });
            });
    }

    public function findProductWithDetails(string $cuid): Product
    {
        return Product::where('cuid', $cuid)
            ->with([
                'category',
                'images',
                'videos',
                'offers',
                'orderUnits',
                'variants.prices.factory',
                'variants.prices.factoryLocation',
            ])
            ->firstOrFail();
    }

    public function getProductFactories(int $productId): Collection
    {
        return Factory::whereHas('prices.productVariant', fn($q) => $q->where('product_id', $productId))
            ->with('locations')
            ->get();
    }
}
