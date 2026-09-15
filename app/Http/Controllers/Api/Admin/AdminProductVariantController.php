<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductSpecRules;
use App\Http\Resources\Product\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Product\ProductCatalogWriter;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پنل مدیریت — CRUD واریانت‌های محصول + اتصال ویژگی‌ها.
 *
 * هر واریانت یک ترکیب مشخص از محصول است (مثلاً «میلگرد ۱۲»). ویژگی‌های واریانت
 * در pivot جدول product_variant_attributes به صورت (attribute_id, attribute_value_id)
 * ذخیره می‌شوند. حذف واریانتِ استفاده‌شده در سفارش (FK) ممکن نیست → پیام غیرفعال‌سازی.
 *
 * ⚠️ نوشتن از `ProductCatalogWriter` و قواعد از `ProductSpecRules` — مشترک با
 * پذیرشِ درخواستِ تأمین‌کننده. قاعده‌ی محلی این‌جا نگذارید.
 */
class AdminProductVariantController extends Controller
{
    public function __construct(private readonly ProductCatalogWriter $writer) {}

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
        $variant = $this->writer->createVariant($product, $request->validate(ProductSpecRules::variant()));

        return created(new ProductVariantResource($variant), __('admin.variants.created'));
    }

    public function show(ProductVariant $variant): JsonResponse
    {
        $variant->load(['prices.factory', 'prices.factoryLocation', 'variantAttributes', 'limitOrderUnit']);

        return success(new ProductVariantResource($variant), __('admin.variants.received'));
    }

    public function update(Request $request, ProductVariant $variant): JsonResponse
    {
        $this->writer->updateVariant($variant, $request->validate(ProductSpecRules::variant($variant->id, partial: true)));

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
        $data = $request->validate(ProductSpecRules::attributes());

        $this->writer->syncVariantAttributes($variant, $data['attributes']);

        return success(
            new ProductVariantResource($variant->load('variantAttributes')),
            __('admin.variants.attributes_synced')
        );
    }
}
