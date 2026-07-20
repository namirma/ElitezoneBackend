<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\ProductPriceResource;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\ProductPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * پنل خودسرویس سوپلایر — مدیریت قیمت محصولات، فقط برای کارخانه/انبارهای خودش.
 *
 * قیمت به سه‌تایی (واریانت × کارخانه × انبار) گره خورده و یکتاست — دقیقاً مثل
 * AdminProductPriceController؛ کش قیمت پایه به‌طور خودکار توسط ProductPrice::booted()
 * فلاش می‌شود. فرق اصلی با نسخه‌ی ادمین: factory_id/factory_location_id باید هر دو
 * به سوپلایر جاری تعلق داشته باشند و با هم سازگار باشند (location واقعاً زیرِ همان
 * factory)، وگرنه یک سوپلایر می‌توانست زیر کارخانه‌ی سوپلایر دیگری قیمت ثبت کند.
 * محصول/واریانت را خودِ سوپلایر نمی‌سازد — کاتالوگ محصولات ادمین‌محور باقی می‌ماند؛
 * سوپلایر فقط برای واریانت‌های موجود، در کارخانه‌ی خودش قیمت تعیین می‌کند.
 */
class SupplierProductPriceController extends Controller
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
            ->whereHas('factory', fn ($q) => $q->where(Factory::SUPPLIER_USER_ID, Auth::id()))
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

    /** Update only mutates the price values — the (variant,factory,location) triple is the row identity. */
    public function update(Request $request, ProductPrice $price): JsonResponse
    {
        $this->authorize('update', $price);

        $data = $request->validate($this->updateRules());
        $data[ProductPrice::LAST_UPDATED_AT] = now();

        $price->update($data);

        return success(new ProductPriceResource($price->load(['factory', 'factoryLocation'])), __('admin.prices.updated'));
    }

    public function destroy(ProductPrice $price): JsonResponse
    {
        $this->authorize('delete', $price);

        $price->delete();

        return success(null, __('admin.prices.deleted'));
    }

    /** Create needs the full identity triple + prices; factory/location must both belong to me and match each other. */
    private function storeRules(): array
    {
        return [
            ProductPrice::PRODUCT_VARIANT_ID => ['required', 'integer', 'exists:product_variants,id'],
            ProductPrice::FACTORY_ID => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $owned = Factory::where(Factory::ID, $value)
                        ->where(Factory::SUPPLIER_USER_ID, Auth::id())
                        ->exists();

                    if (! $owned) {
                        $fail(__('auth.policy.factory_access_denied'));
                    }
                },
            ],
            ProductPrice::FACTORY_LOCATION_ID => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $location = FactoryLocation::find($value);

                    if (! $location || (int) $location->factory?->{Factory::SUPPLIER_USER_ID} !== (int) Auth::id()) {
                        $fail(__('auth.policy.factory_access_denied'));

                        return;
                    }

                    if ((int) $location->{FactoryLocation::FACTORY_ID} !== (int) request()->integer(ProductPrice::FACTORY_ID)) {
                        $fail(__('admin.prices.location_factory_mismatch'));
                    }
                },
            ],
            ProductPrice::GENERAL_PRICE => ['required', 'numeric', 'min:0'],
            ProductPrice::SPECIAL_PRICE => ['required', 'numeric', 'min:0'],
            ProductPrice::GOLDEN_FIGURE => ['required', 'numeric', 'min:0'],
        ];
    }

    private function updateRules(): array
    {
        return [
            ProductPrice::GENERAL_PRICE => ['sometimes', 'required', 'numeric', 'min:0'],
            ProductPrice::SPECIAL_PRICE => ['sometimes', 'required', 'numeric', 'min:0'],
            ProductPrice::GOLDEN_FIGURE => ['sometimes', 'required', 'numeric', 'min:0'],
        ];
    }
}
