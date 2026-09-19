<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Supplier\FactoryLocationResource;
use App\Models\FactoryLocation;
use App\Models\ProductPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * پنل مدیریت — CRUD انبارها/مبادی کارخانه (FactoryLocation).
 *
 * هر انبار به یک کارخانه تعلق دارد و مبدأ قیمت‌گذاری و حمل است. انباری که برای آن
 * قیمت محصول ثبت شده، قابل حذف نیست (تا قیمت‌ها orphan نشوند).
 */
class AdminFactoryLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'factory_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $locations = FactoryLocation::query()
            ->when($request->filled('factory_id'), fn ($q) => $q->where(FactoryLocation::FACTORY_ID, $request->integer('factory_id')))
            ->when($request->has('is_active'), fn ($q) => $q->where(FactoryLocation::IS_ACTIVE, $request->boolean('is_active')))
            ->orderBy(FactoryLocation::FACTORY_ID)
            ->orderBy(FactoryLocation::NAME)
            ->paginate($this->perPage(100));

        return success(FactoryLocationResource::collection($locations), __('admin.factory_locations.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $location = FactoryLocation::create($request->validate($this->rules()));

        return created(new FactoryLocationResource($location), __('admin.factory_locations.created'));
    }

    public function update(Request $request, FactoryLocation $factoryLocation): JsonResponse
    {
        $factoryLocation->update($request->validate($this->rules(true)));

        return success(new FactoryLocationResource($factoryLocation), __('admin.factory_locations.updated'));
    }

    public function destroy(FactoryLocation $factoryLocation): JsonResponse
    {
        if (ProductPrice::where(ProductPrice::FACTORY_LOCATION_ID, $factoryLocation->id)->exists()) {
            return error(__('admin.factory_locations.in_use'), [], 422);
        }

        // مثل نسخه‌ی تأمین‌کننده — `FactoryLocation::booted()` موجودیِ مبدأ را پیش از
        // حذف پاک و رول‌آپ را بازسازی می‌کند، پس هر دو باید یک‌جا اتفاق بیفتند.
        DB::transaction(fn () => $factoryLocation->delete());

        return success(null, __('admin.factory_locations.deleted'));
    }

    public function toggle(FactoryLocation $factoryLocation): JsonResponse
    {
        $factoryLocation->update([FactoryLocation::IS_ACTIVE => ! $factoryLocation->{FactoryLocation::IS_ACTIVE}]);

        $message = $factoryLocation->{FactoryLocation::IS_ACTIVE}
            ? __('admin.factory_locations.toggled_active')
            : __('admin.factory_locations.toggled_inactive');

        return success(new FactoryLocationResource($factoryLocation), $message);
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            FactoryLocation::FACTORY_ID => [$req, 'integer', 'exists:factories,id'],
            FactoryLocation::NAME => [$req, 'string', 'max:255'],
            FactoryLocation::TYPE => [$req, 'string', 'max:255'],
            FactoryLocation::CITY => ['nullable', 'string', 'max:255'],
            FactoryLocation::ADDRESS => ['nullable', 'string'],
            FactoryLocation::IS_ACTIVE => ['boolean'],
        ];
    }
}
