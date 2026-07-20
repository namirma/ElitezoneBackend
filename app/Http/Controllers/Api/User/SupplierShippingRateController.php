<?php

namespace App\Http\Controllers\Api\User;

use App\Enums\Shipping\ShippingCostBasis;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\ShippingRateResource;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\ShippingRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * پنل خودسرویس سوپلایر — CRUD نرخ‌های حمل، فقط برای انبارهای خودش (مبدأ مسیر).
 *
 * دقیقاً معادل AdminShippingRateController — همان قوانین یکتاییِ مسیر (با در نظر
 * گرفتن NULL city) و همان مدل هزینه (flat + weight یا count). فرق اصلی:
 * factory_location_id باید به یکی از کارخانه‌های خود سوپلایر تعلق داشته باشد.
 */
class SupplierShippingRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'factory_location_id' => ['nullable', 'integer'],
            'province_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $rates = ShippingRate::query()
            ->whereHas('factoryLocation.factory', fn ($q) => $q->where(Factory::SUPPLIER_USER_ID, Auth::id()))
            ->with('factoryLocation.factory')
            ->when(
                $request->filled('factory_location_id'),
                fn ($q) => $q->where(ShippingRate::FACTORY_LOCATION_ID, $request->integer('factory_location_id'))
            )
            ->when(
                $request->filled('province_id'),
                fn ($q) => $q->where(ShippingRate::DESTINATION_PROVINCE_ID, $request->integer('province_id'))
            )
            ->when(
                $request->has('is_active'),
                fn ($q) => $q->where(ShippingRate::IS_ACTIVE, $request->boolean('is_active'))
            )
            ->orderBy(ShippingRate::FACTORY_LOCATION_ID)
            ->orderBy(ShippingRate::DESTINATION_PROVINCE_ID)
            ->orderByRaw(ShippingRate::DESTINATION_CITY_ID.' IS NULL')
            ->orderBy(ShippingRate::DESTINATION_CITY_ID)
            ->paginate($this->perPage(50));

        return success(ShippingRateResource::collection($rates), __('admin.shipping_rates.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        if ($this->routeExists($data)) {
            return error(__('admin.shipping_rates.duplicate_route'), [], 422);
        }

        $rate = ShippingRate::create($data);
        $rate->load('factoryLocation.factory');

        return success(new ShippingRateResource($rate), __('admin.shipping_rates.created'));
    }

    public function update(Request $request, ShippingRate $shippingRate): JsonResponse
    {
        $this->authorize('update', $shippingRate);

        $data = $request->validate($this->rules());

        if ($this->routeExists($data, $shippingRate->id)) {
            return error(__('admin.shipping_rates.duplicate_route'), [], 422);
        }

        $shippingRate->update($data);
        $shippingRate->load('factoryLocation.factory');

        return success(new ShippingRateResource($shippingRate), __('admin.shipping_rates.updated'));
    }

    public function destroy(ShippingRate $shippingRate): JsonResponse
    {
        $this->authorize('delete', $shippingRate);

        $shippingRate->delete();

        return success(null, __('admin.shipping_rates.deleted'));
    }

    public function toggle(ShippingRate $shippingRate): JsonResponse
    {
        $this->authorize('update', $shippingRate);

        $shippingRate->update([ShippingRate::IS_ACTIVE => ! $shippingRate->{ShippingRate::IS_ACTIVE}]);

        $message = $shippingRate->{ShippingRate::IS_ACTIVE}
            ? __('admin.shipping_rates.toggled_active')
            : __('admin.shipping_rates.toggled_inactive');

        return success(new ShippingRateResource($shippingRate), $message);
    }

    /**
     * قوانین اعتبارسنجی مشترک برای store و update (مثل نسخه‌ی ادمین، هر دو عملیات
     * کل سه‌تایی هویت را می‌خواهند — بدون partial update). فقط factory_location_id
     * اضافه‌تر از نسخه‌ی ادمین: باید مالِ خودِ سوپلایر باشد.
     */
    private function rules(): array
    {
        return [
            'factory_location_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $owned = FactoryLocation::where(FactoryLocation::ID, $value)
                        ->whereHas('factory', fn ($q) => $q->where(Factory::SUPPLIER_USER_ID, Auth::id()))
                        ->exists();

                    if (! $owned) {
                        $fail(__('auth.policy.factory_access_denied'));
                    }
                },
            ],
            'destination_province_id' => ['required', 'integer', 'min:1'],
            'destination_city_id' => ['nullable', 'integer', 'min:1'],
            'flat_cost' => ['required', 'numeric', 'min:0'],
            'per_kg_cost' => ['required', 'numeric', 'min:0'],
            'cost_basis' => ['required', Rule::enum(ShippingCostBasis::class)],
            'per_count_cost' => ['required_if:cost_basis,count', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * بررسی تکراری بودن مسیر — با رعایت NULL city (مثل نسخه‌ی ادمین؛ MySQL UNIQUE
     * روی NULL درست کار نمی‌کند، پس چک دستی می‌کنیم).
     */
    private function routeExists(array $data, ?int $ignoreId = null): bool
    {
        $query = ShippingRate::query()
            ->where(ShippingRate::FACTORY_LOCATION_ID, $data['factory_location_id'])
            ->where(ShippingRate::DESTINATION_PROVINCE_ID, $data['destination_province_id']);

        $cityId = $data['destination_city_id'] ?? null;
        if ($cityId !== null) {
            $query->where(ShippingRate::DESTINATION_CITY_ID, $cityId);
        } else {
            $query->whereNull(ShippingRate::DESTINATION_CITY_ID);
        }

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }
}
