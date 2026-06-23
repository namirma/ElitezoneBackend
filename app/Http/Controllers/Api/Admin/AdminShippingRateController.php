<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Shipping\ShippingCostBasis;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\ShippingRateResource;
use App\Models\ShippingRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD نرخ‌های حمل‌ونقل
 *
 * هر ردیف نرخ یک مسیر حمل را تعریف می‌کند:
 *   مبدأ (factory_location) → مقصد (province / optional city)
 *
 * اگر ردیف city-specific وجود داشت → اولویت دارد.
 * ردیف province-level (city = null) → fallback.
 * بدون ردیف منطبق → هزینه صفر (تا زمانی که ادمین نرخ وارد کند).
 */
class AdminShippingRateController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    // GET /admin/shipping-rates
    // ─────────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'factory_location_id' => ['nullable', 'integer'],
            'province_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $rates = ShippingRate::query()
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
            ->orderByRaw(ShippingRate::DESTINATION_CITY_ID.' IS NULL')  // province-level rows last
            ->orderBy(ShippingRate::DESTINATION_CITY_ID)
            ->paginate($this->perPage(50));

        return success(ShippingRateResource::collection($rates), __('admin.shipping_rates.list_received'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /admin/shipping-rates
    // ─────────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        // unique بودن مسیر — با در نظر گرفتن NULL city
        if ($this->routeExists($data)) {
            return error(__('admin.shipping_rates.duplicate_route'), [], 422);
        }

        $rate = ShippingRate::create($data);
        $rate->load('factoryLocation.factory');

        return success(new ShippingRateResource($rate), __('admin.shipping_rates.created'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUT /admin/shipping-rates/{shippingRate}
    // ─────────────────────────────────────────────────────────────────────
    public function update(Request $request, ShippingRate $shippingRate): JsonResponse
    {
        $data = $request->validate($this->rules($shippingRate->id));

        // unique بودن مسیر — ردیف خودی را نادیده بگیر
        if ($this->routeExists($data, $shippingRate->id)) {
            return error(__('admin.shipping_rates.duplicate_route'), [], 422);
        }

        $shippingRate->update($data);
        $shippingRate->load('factoryLocation.factory');

        return success(new ShippingRateResource($shippingRate), __('admin.shipping_rates.updated'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // DELETE /admin/shipping-rates/{shippingRate}
    // ─────────────────────────────────────────────────────────────────────
    public function destroy(ShippingRate $shippingRate): JsonResponse
    {
        $shippingRate->delete();

        return success(null, __('admin.shipping_rates.deleted'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /admin/shipping-rates/{shippingRate}/toggle
    // ─────────────────────────────────────────────────────────────────────
    public function toggle(ShippingRate $shippingRate): JsonResponse
    {
        $shippingRate->update([ShippingRate::IS_ACTIVE => ! $shippingRate->{ShippingRate::IS_ACTIVE}]);

        $message = $shippingRate->{ShippingRate::IS_ACTIVE}
            ? __('admin.shipping_rates.toggled_active')
            : __('admin.shipping_rates.toggled_inactive');

        return success(new ShippingRateResource($shippingRate), $message);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * قوانین اعتبارسنجی مشترک برای store و update.
     */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'factory_location_id' => ['required', 'integer', 'exists:factory_locations,id'],
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
     * بررسی تکراری بودن مسیر — با رعایت NULL city.
     *
     * MySQL UNIQUE constraint روی NULL values به درستی کار نمی‌کند،
     * پس چک دستی می‌کنیم.
     */
    private function routeExists(array $data, ?int $ignoreId = null): bool
    {
        $query = ShippingRate::query()
            ->where(ShippingRate::FACTORY_LOCATION_ID, $data['factory_location_id'])
            ->where(ShippingRate::DESTINATION_PROVINCE_ID, $data['destination_province_id']);

        // handle nullable city
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
