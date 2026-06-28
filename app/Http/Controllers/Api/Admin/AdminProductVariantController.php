<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD واریانت‌های محصول + اتصال ویژگی‌ها.
 *
 * هر واریانت یک ترکیب مشخص از محصول است (مثلاً «میلگرد ۱۲»). ویژگی‌های واریانت
 * در pivot جدول product_variant_attributes به صورت (attribute_id, attribute_value_id)
 * ذخیره می‌شوند. حذف واریانتِ استفاده‌شده در سفارش (FK) ممکن نیست → پیام غیرفعال‌سازی.
 */
class AdminProductVariantController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        $variants = $product->variants()
            ->with(['prices.factory', 'prices.factoryLocation', 'variantAttributes', 'limitOrderUnit'])
            ->orderBy(ProductVariant::NAME)
            ->paginate($this->perPage(50));

        return success(ProductVariantResource::collection($variants), __('admin.variants.list_received'));
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data[ProductVariant::PRODUCT_ID] = $product->id;

        $variant = ProductVariant::create($data);

        return created(new ProductVariantResource($variant), __('admin.variants.created'));
    }

    public function show(ProductVariant $variant): JsonResponse
    {
        $variant->load(['prices.factory', 'prices.factoryLocation', 'variantAttributes', 'limitOrderUnit']);

        return success(new ProductVariantResource($variant), __('admin.variants.received'));
    }

    public function update(Request $request, ProductVariant $variant): JsonResponse
    {
        $variant->update($request->validate($this->rules($variant->id)));

        return success(new ProductVariantResource($variant), __('admin.variants.updated'));
    }

    public function destroy(ProductVariant $variant): JsonResponse
    {
        try {
            $variant->delete();
        } catch (QueryException) {
            return error(__('admin.variants.delete_blocked'), [], 422);
        }

        return success(null, __('admin.variants.deleted'));
    }

    public function toggle(ProductVariant $variant): JsonResponse
    {
        $variant->update([ProductVariant::IS_ACTIVE => ! $variant->{ProductVariant::IS_ACTIVE}]);

        $message = $variant->{ProductVariant::IS_ACTIVE}
            ? __('admin.variants.toggled_active')
            : __('admin.variants.toggled_inactive');

        return success(new ProductVariantResource($variant), $message);
    }

    /**
     * اتصال ویژگی‌ها به واریانت. ورودی آرایه‌ای از جفت‌های (attribute_id, attribute_value_id)
     * است؛ pivot با sync جایگزین می‌شود (نه انباشت).
     */
    public function syncAttributes(Request $request, ProductVariant $variant): JsonResponse
    {
        $data = $request->validate([
            'attributes' => ['present', 'array'],
            'attributes.*.attribute_id' => ['required', 'integer', 'exists:attributes,id'],
            'attributes.*.attribute_value_id' => ['required', 'integer', 'exists:attribute_values,id'],
        ]);

        $sync = [];
        foreach ($data['attributes'] as $pair) {
            $sync[$pair['attribute_id']] = ['attribute_value_id' => $pair['attribute_value_id']];
        }
        $variant->variantAttributes()->sync($sync);

        return success(
            new ProductVariantResource($variant->load('variantAttributes')),
            __('admin.variants.attributes_synced')
        );
    }

    private function rules(?int $ignoreId = null): array
    {
        $req = $ignoreId ? 'sometimes' : 'required';

        return [
            ProductVariant::NAME => [$req, 'string', 'max:255'],
            ProductVariant::SKU => [$req, 'string', 'max:255', Rule::unique('product_variants', 'sku')->ignore($ignoreId)],
            ProductVariant::IS_ACTIVE => ['boolean'],
            ProductVariant::STOCK_QUANTITY => ['nullable', 'integer', 'min:0'],
            ProductVariant::MIN_ORDER_VALUE => ['nullable', 'numeric', 'min:0'],
            ProductVariant::MAX_ORDER_VALUE => ['nullable', 'numeric', 'min:0'],
            ProductVariant::LIMIT_ORDER_UNIT_ID => ['nullable', 'integer', 'exists:order_units,id'],
            ProductVariant::DISCOUNT_VALUE => ['nullable', 'numeric', 'min:0'],
            ProductVariant::DISCOUNT_TYPE => ['nullable', Rule::in(['percentage', 'fixed_amount'])],
            ProductVariant::DISCOUNT_START_DATE => ['nullable', 'date'],
            ProductVariant::DISCOUNT_END_DATE => ['nullable', 'date', 'after_or_equal:discount_start_date'],
        ];
    }
}
