<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\PriceFilterCategoryResource;
use App\Models\PriceFilterCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD دسته‌ی فیلتر قیمت (PriceFilterCategory) + اتصال فیلترها.
 *
 * هر دسته مجموعه‌ای از PriceFilterها را گروه می‌کند (نقش قیمتی کاربر). اتصال از طریق
 * pivot جدول price_filter_category_items است (متد filters()). CRUD خودِ دسته توسط
 * booted() کش را فلاش می‌کند، اما sync کردن pivot رویداد save مدل را آتش نمی‌زند،
 * پس در syncFilters کش را دستی فلاش می‌کنیم.
 */
class AdminPriceFilterCategoryController extends Controller
{
    private const PRICING_CACHE_KEY = 'pricing:categories_with_filters';

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $categories = PriceFilterCategory::query()
            ->when($request->has('is_active'), fn ($q) => $q->where(PriceFilterCategory::IS_ACTIVE, $request->boolean('is_active')))
            ->with('filters.filterType')
            ->orderBy(PriceFilterCategory::SORT_ORDER)
            ->orderBy(PriceFilterCategory::NAME)
            ->paginate($this->perPage(100));

        return success(PriceFilterCategoryResource::collection($categories), __('admin.filter_categories.list_received'));
    }

    public function show(PriceFilterCategory $filterCategory): JsonResponse
    {
        return success(new PriceFilterCategoryResource($filterCategory->load('filters.filterType')), __('admin.filter_categories.received'));
    }

    public function store(Request $request): JsonResponse
    {
        $category = PriceFilterCategory::create($request->validate($this->rules()));

        return created(new PriceFilterCategoryResource($category->load('filters.filterType')), __('admin.filter_categories.created'));
    }

    public function update(Request $request, PriceFilterCategory $filterCategory): JsonResponse
    {
        $filterCategory->update($request->validate($this->rules($filterCategory->id)));

        return success(new PriceFilterCategoryResource($filterCategory->load('filters.filterType')), __('admin.filter_categories.updated'));
    }

    public function destroy(PriceFilterCategory $filterCategory): JsonResponse
    {
        $filterCategory->delete();

        return success(null, __('admin.filter_categories.deleted'));
    }

    public function toggle(PriceFilterCategory $filterCategory): JsonResponse
    {
        $filterCategory->update([PriceFilterCategory::IS_ACTIVE => ! $filterCategory->{PriceFilterCategory::IS_ACTIVE}]);

        $message = $filterCategory->{PriceFilterCategory::IS_ACTIVE}
            ? __('admin.filter_categories.toggled_active')
            : __('admin.filter_categories.toggled_inactive');

        return success(new PriceFilterCategoryResource($filterCategory->load('filters.filterType')), $message);
    }

    /** اتصال/جایگزینی فیلترهای یک دسته (pivot sync). کش را دستی فلاش می‌کند. */
    public function syncFilters(Request $request, PriceFilterCategory $filterCategory): JsonResponse
    {
        $data = $request->validate([
            'filter_ids' => ['present', 'array'],
            'filter_ids.*' => ['integer', 'exists:price_filters,id'],
        ]);

        $filterCategory->filters()->sync($data['filter_ids']);
        Cache::forget(self::PRICING_CACHE_KEY);

        return success(new PriceFilterCategoryResource($filterCategory->load('filters.filterType')), __('admin.filter_categories.filters_synced'));
    }

    private function rules(?int $ignoreId = null): array
    {
        $req = $ignoreId ? 'sometimes' : 'required';

        return [
            PriceFilterCategory::NAME => [$req, 'string', 'max:255'],
            PriceFilterCategory::CODE => [$req, 'string', 'max:255', Rule::unique('price_filter_categories', 'code')->ignore($ignoreId)],
            PriceFilterCategory::DESCRIPTION => ['nullable', 'string'],
            PriceFilterCategory::IS_ACTIVE => ['boolean'],
            PriceFilterCategory::SORT_ORDER => ['nullable', 'integer', 'min:0'],
        ];
    }
}
