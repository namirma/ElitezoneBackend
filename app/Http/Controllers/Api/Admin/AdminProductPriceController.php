<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\ProductPriceResource;
use App\Models\ProductPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پنل مدیریت — مدیریت قیمت محصول.
 *
 * قیمت به سه‌تایی (واریانت × کارخانه × انبار) گره خورده و یکتاست. کش قیمت پایه
 * توسط ProductPrice::booted() در رویدادهای created/updated/deleted خودکار فلاش می‌شود،
 * پس اینجا نیازی به دست‌کاری کش نیست. سه نوع قیمت ذخیره می‌شود:
 *   general_price (عمومی) · special_price (ویژه) · golden_figure (رقم طلایی).
 */
class AdminProductPriceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'product_variant_id' => ['nullable', 'integer'],
            'factory_id' => ['nullable', 'integer'],
            'factory_location_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $prices = ProductPrice::query()
            ->when($request->filled('product_variant_id'), fn ($q) => $q->where(ProductPrice::PRODUCT_VARIANT_ID, $request->integer('product_variant_id')))
            ->when($request->filled('factory_id'), fn ($q) => $q->where(ProductPrice::FACTORY_ID, $request->integer('factory_id')))
            ->when($request->filled('factory_location_id'), fn ($q) => $q->where(ProductPrice::FACTORY_LOCATION_ID, $request->integer('factory_location_id')))
            ->with(['factory', 'factoryLocation'])
            ->latest(ProductPrice::ID)
            ->paginate($this->perPage(50));

        return success(ProductPriceResource::collection($prices), __('admin.prices.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->storeRules());

        $exists = ProductPrice::query()
            ->where(ProductPrice::PRODUCT_VARIANT_ID, $data[ProductPrice::PRODUCT_VARIANT_ID])
            ->where(ProductPrice::FACTORY_ID, $data[ProductPrice::FACTORY_ID])
            ->where(ProductPrice::FACTORY_LOCATION_ID, $data[ProductPrice::FACTORY_LOCATION_ID])
            ->exists();

        if ($exists) {
            return error(__('admin.prices.duplicate'), [], 422);
        }

        $data[ProductPrice::LAST_UPDATED_AT] = now();
        $price = ProductPrice::create($data);

        return created(new ProductPriceResource($price->load(['factory', 'factoryLocation'])), __('admin.prices.created'));
    }

    public function update(Request $request, ProductPrice $price): JsonResponse
    {
        $data = $request->validate($this->updateRules());
        $data[ProductPrice::LAST_UPDATED_AT] = now();

        $price->update($data);

        return success(new ProductPriceResource($price->load(['factory', 'factoryLocation'])), __('admin.prices.updated'));
    }

    public function destroy(ProductPrice $price): JsonResponse
    {
        $price->delete();

        return success(null, __('admin.prices.deleted'));
    }

    /** Create needs the full identity triple + prices. */
    private function storeRules(): array
    {
        return [
            ProductPrice::PRODUCT_VARIANT_ID => ['required', 'integer', 'exists:product_variants,id'],
            ProductPrice::FACTORY_ID => ['required', 'integer', 'exists:factories,id'],
            ProductPrice::FACTORY_LOCATION_ID => ['required', 'integer', 'exists:factory_locations,id'],
            ProductPrice::GENERAL_PRICE => ['required', 'numeric', 'min:0'],
            ProductPrice::SPECIAL_PRICE => ['required', 'numeric', 'min:0'],
            ProductPrice::GOLDEN_FIGURE => ['required', 'numeric', 'min:0'],
        ];
    }

    /** Update only mutates the price values — the (variant,factory,location) triple is the row identity. */
    private function updateRules(): array
    {
        return [
            ProductPrice::GENERAL_PRICE => ['sometimes', 'required', 'numeric', 'min:0'],
            ProductPrice::SPECIAL_PRICE => ['sometimes', 'required', 'numeric', 'min:0'],
            ProductPrice::GOLDEN_FIGURE => ['sometimes', 'required', 'numeric', 'min:0'],
        ];
    }
}
