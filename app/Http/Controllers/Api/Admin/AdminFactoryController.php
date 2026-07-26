<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Supplier\FactoryResource;
use App\Models\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD کارخانه‌ها (Factory).
 *
 * کارخانه مبدأ تولید است و انبارها/مبادی (FactoryLocation) زیرمجموعه‌ی آن.
 * قیمت محصول به (واریانت × کارخانه × انبار) گره خورده، پس حذف کارخانه‌ای که انبار
 * دارد مجاز نیست. لوگو روی دیسک public ذخیره می‌شود.
 */
class AdminFactoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $factories = Factory::query()
            ->when($request->has('is_active'), fn ($q) => $q->where(Factory::IS_ACTIVE, $request->boolean('is_active')))
            ->when($request->filled('q'), fn ($q) => $q->where(Factory::NAME, 'like', '%'.$request->string('q').'%'))
            ->withCount('locations')
            ->orderBy(Factory::NAME)
            ->paginate($this->perPage(50));

        return success(FactoryResource::collection($factories), __('admin.factories.list_received'));
    }

    public function show(Factory $factory): JsonResponse
    {
        return success(new FactoryResource($factory->load('locations')), __('admin.factories.received'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        if ($request->hasFile(Factory::LOGO)) {
            $data[Factory::LOGO] = $request->file(Factory::LOGO)->store('factories', 'public');
        }

        $factory = Factory::create($data);

        return created(new FactoryResource($factory), __('admin.factories.created'));
    }

    public function update(Request $request, Factory $factory): JsonResponse
    {
        $data = $request->validate($this->rules($factory->id));

        if ($request->hasFile(Factory::LOGO)) {
            $this->deleteLogoIfOwned($factory);
            $data[Factory::LOGO] = $request->file(Factory::LOGO)->store('factories', 'public');
        }

        $factory->update($data);

        return success(new FactoryResource($factory->refresh()), __('admin.factories.updated'));
    }

    public function destroy(Factory $factory): JsonResponse
    {
        if ($factory->locations()->exists()) {
            return error(__('admin.factories.has_locations'), [], 422);
        }

        $this->deleteLogoIfOwned($factory);
        $factory->delete();

        return success(null, __('admin.factories.deleted'));
    }

    public function toggle(Factory $factory): JsonResponse
    {
        $factory->update([Factory::IS_ACTIVE => ! $factory->{Factory::IS_ACTIVE}]);

        $message = $factory->{Factory::IS_ACTIVE}
            ? __('admin.factories.toggled_active')
            : __('admin.factories.toggled_inactive');

        return success(new FactoryResource($factory), $message);
    }

    private function rules(?int $ignoreId = null): array
    {
        $req = $ignoreId ? 'sometimes' : 'required';

        return [
            Factory::NAME => [$req, 'string', 'max:255'],
            Factory::SLUG => [$req, 'string', 'max:255', Rule::unique('factories', 'slug')->ignore($ignoreId)],
            Factory::DESCRIPTION => ['nullable', 'string'],
            Factory::API_KEY => ['nullable', 'string', 'max:255'],
            Factory::API_URL => ['nullable', 'string', 'max:255'],
            Factory::SUPPLIER_USER_ID => ['nullable', 'integer', 'exists:users,id'],
            Factory::IS_ACTIVE => ['boolean'],
            // svg عمداً نیست — آپلود روی دیسک public نباید SVG بپذیرد (XSS ذخیره‌شده).
            // باید با SupplierFactoryController::rules() یکسان بماند.
            Factory::LOGO => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    private function deleteLogoIfOwned(Factory $factory): void
    {
        $logo = $factory->{Factory::LOGO};

        if ($logo && ! str_starts_with($logo, 'http') && Storage::disk('public')->exists($logo)) {
            Storage::disk('public')->delete($logo);
        }
    }
}
