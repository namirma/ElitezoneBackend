<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\Supplier\FactoryLocationResource;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\ProductPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * پنل خودسرویس سوپلایر — CRUD انبارها/مبادی، فقط برای کارخانه‌های خودش.
 *
 * factory_id هرگز با exists:factories,id ساده اعتبارسنجی نمی‌شود — باید متعلق به
 * یکی از کارخانه‌های خود سوپلایر باشد، وگرنه یک سوپلایر می‌توانست انبار جعلی زیر
 * کارخانه‌ی سوپلایر دیگری بسازد (همان الگوی closure rule که StoreOrderRequest
 * برای elite_yar_user_id/beneficiary_user_id استفاده می‌کند).
 */
class SupplierFactoryLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'factory_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $locations = FactoryLocation::query()
            ->whereHas('factory', fn ($q) => $q->where(Factory::SUPPLIER_USER_ID, Auth::id()))
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
        $this->authorize('update', $factoryLocation);

        $factoryLocation->update($request->validate($this->rules(true)));

        return success(new FactoryLocationResource($factoryLocation), __('admin.factory_locations.updated'));
    }

    public function destroy(FactoryLocation $factoryLocation): JsonResponse
    {
        $this->authorize('delete', $factoryLocation);

        if (ProductPrice::where(ProductPrice::FACTORY_LOCATION_ID, $factoryLocation->id)->exists()) {
            return error(__('admin.factory_locations.in_use'), [], 422);
        }

        // تراکنش لازم است چون `FactoryLocation::booted()` پیش از حذفِ خودِ ردیف،
        // موجودی‌های این مبدأ را پاک و رول‌آپ را بازسازی می‌کند. اگر حذفِ انبار بعد
        // از آن به قید کلید خارجیِ دیگری بخورد، بدون تراکنش موجودی رفته بود و انبار
        // مانده بود.
        DB::transaction(fn () => $factoryLocation->delete());

        return success(null, __('admin.factory_locations.deleted'));
    }

    public function toggle(FactoryLocation $factoryLocation): JsonResponse
    {
        $this->authorize('update', $factoryLocation);

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
            FactoryLocation::FACTORY_ID => [
                $req,
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
            FactoryLocation::NAME => [$req, 'string', 'max:255'],
            FactoryLocation::TYPE => [$req, 'string', 'max:255'],
            FactoryLocation::CITY => ['nullable', 'string', 'max:255'],
            FactoryLocation::ADDRESS => ['nullable', 'string'],
            FactoryLocation::IS_ACTIVE => ['boolean'],
        ];
    }
}
