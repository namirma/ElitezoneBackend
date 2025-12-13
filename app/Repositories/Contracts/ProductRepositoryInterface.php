<?php

namespace App\Repositories\Contracts;

use App\DataTransferObjects\ProductFilterData;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

interface ProductRepositoryInterface
{
    /**
     * دریافت کوئری ساز بر اساس فیلترها (بدون اجرا)
     * این به Action اجازه می‌دهد قبل از دریافت، کارهایی مثل Eager Loading اضافه کند.
     */
    public function getFilterQuery(ProductFilterData $filters): Builder;

    /**
     * یافتن محصول با تمام جزئیات (برای صفحه سینگل)
     */
    public function findProductWithDetails(string $cuid): Product;

    /**
     * دریافت کارخانه‌های مرتبط با محصول
     */
    public function getProductFactories(int $productId): Collection;
}
