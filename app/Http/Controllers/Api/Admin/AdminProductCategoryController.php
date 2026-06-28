<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\ProductCategoryResource;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD دسته‌بندی محصولات.
 *
 * تصویر دسته روی دیسک public ذخیره می‌شود. حذف دسته‌ای که محصول دارد مجاز نیست
 * (تا از orphan شدن محصولات جلوگیری شود).
 */
class AdminProductCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $categories = ProductCategory::query()
            ->when($request->has('is_active'), fn ($q) => $q->where(ProductCategory::IS_ACTIVE, $request->boolean('is_active')))
            ->when($request->filled('q'), fn ($q) => $q->where(ProductCategory::NAME, 'like', '%'.$request->string('q').'%'))
            ->withCount('products')
            ->orderBy(ProductCategory::NAME)
            ->paginate($this->perPage(50));

        return success(ProductCategoryResource::collection($categories), __('admin.categories.list_received'));
    }

    public function show(ProductCategory $category): JsonResponse
    {
        return success(new ProductCategoryResource($category->loadCount('products')), __('admin.categories.received'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        if ($request->hasFile(ProductCategory::IMAGE)) {
            $data[ProductCategory::IMAGE] = $request->file(ProductCategory::IMAGE)->store('categories', 'public');
        }

        $category = ProductCategory::create($data);

        return created(new ProductCategoryResource($category), __('admin.categories.created'));
    }

    public function update(Request $request, ProductCategory $category): JsonResponse
    {
        $data = $request->validate($this->rules($category->id));

        if ($request->hasFile(ProductCategory::IMAGE)) {
            $this->deleteImageIfOwned($category);
            $data[ProductCategory::IMAGE] = $request->file(ProductCategory::IMAGE)->store('categories', 'public');
        }

        $category->update($data);

        return success(new ProductCategoryResource($category->refresh()), __('admin.categories.updated'));
    }

    public function destroy(ProductCategory $category): JsonResponse
    {
        if ($category->products()->exists()) {
            return error(__('admin.categories.has_products'), [], 422);
        }

        $this->deleteImageIfOwned($category);
        $category->delete();

        return success(null, __('admin.categories.deleted'));
    }

    public function toggle(ProductCategory $category): JsonResponse
    {
        $category->update([ProductCategory::IS_ACTIVE => ! $category->{ProductCategory::IS_ACTIVE}]);

        $message = $category->{ProductCategory::IS_ACTIVE}
            ? __('admin.categories.toggled_active')
            : __('admin.categories.toggled_inactive');

        return success(new ProductCategoryResource($category), $message);
    }

    /** قوانین مشترک store/update — روی update فیلدها sometimes می‌شوند (partial update). */
    private function rules(?int $ignoreId = null): array
    {
        $req = $ignoreId ? 'sometimes' : 'required';

        return [
            ProductCategory::NAME => [$req, 'string', 'max:255'],
            ProductCategory::SLUG => [$req, 'string', 'max:255', Rule::unique('product_categories', 'slug')->ignore($ignoreId)],
            ProductCategory::DESCRIPTION => ['nullable', 'string'],
            ProductCategory::IS_ACTIVE => ['boolean'],
            ProductCategory::IMAGE => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
        ];
    }

    private function deleteImageIfOwned(ProductCategory $category): void
    {
        $image = $category->{ProductCategory::IMAGE};

        if ($image && ! str_starts_with($image, 'http') && Storage::disk('public')->exists($image)) {
            Storage::disk('public')->delete($image);
        }
    }
}
