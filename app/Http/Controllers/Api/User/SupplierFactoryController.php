<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\Supplier\FactoryResource;
use App\Models\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * پنل خودسرویس سوپلایر — CRUD کارخانه‌های خودش.
 *
 * همان قوانین AdminFactoryController را رعایت می‌کند (حذف کارخانه‌ی دارای انبار
 * ممنوع است)، با این تفاوت که scope همیشه روی factories.supplier_user_id = کاربر
 * جاری قفل است. مالکیت (supplier_user_id) هرگز از ورودی کاربر خوانده نمی‌شود —
 * روی store سرور خودش را متصل می‌کند و در update/rules اصلاً قابل ارسال نیست.
 */
class SupplierFactoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $factories = Factory::query()
            ->where(Factory::SUPPLIER_USER_ID, Auth::id())
            ->when($request->has('is_active'), fn ($q) => $q->where(Factory::IS_ACTIVE, $request->boolean('is_active')))
            ->withCount('locations')
            ->orderBy(Factory::NAME)
            ->paginate($this->perPage(50));

        return success(FactoryResource::collection($factories), __('admin.factories.list_received'));
    }

    public function show(Factory $factory): JsonResponse
    {
        $this->authorize('view', $factory);

        return success(new FactoryResource($factory->load('locations')), __('admin.factories.received'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data[Factory::SUPPLIER_USER_ID] = Auth::id();

        if ($request->hasFile(Factory::LOGO)) {
            $data[Factory::LOGO] = $request->file(Factory::LOGO)->store('factories', 'public');
        }

        $factory = Factory::create($data);

        return created(new FactoryResource($factory), __('admin.factories.created'));
    }

    public function update(Request $request, Factory $factory): JsonResponse
    {
        $this->authorize('update', $factory);

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
        $this->authorize('delete', $factory);

        if ($factory->locations()->exists()) {
            return error(__('admin.factories.has_locations'), [], 422);
        }

        $this->deleteLogoIfOwned($factory);
        $factory->delete();

        return success(null, __('admin.factories.deleted'));
    }

    public function toggle(Factory $factory): JsonResponse
    {
        $this->authorize('update', $factory);

        $factory->update([Factory::IS_ACTIVE => ! $factory->{Factory::IS_ACTIVE}]);

        $message = $factory->{Factory::IS_ACTIVE}
            ? __('admin.factories.toggled_active')
            : __('admin.factories.toggled_inactive');

        return success(new FactoryResource($factory), $message);
    }

    /** ستون‌های قابل ویرایش سوپلایر — supplier_user_id عمداً اینجا نیست. */
    private function rules(?int $ignoreId = null): array
    {
        $req = $ignoreId ? 'sometimes' : 'required';

        return [
            Factory::NAME => [$req, 'string', 'max:255'],
            Factory::SLUG => [$req, 'string', 'max:255', Rule::unique('factories', 'slug')->ignore($ignoreId)],
            Factory::DESCRIPTION => ['nullable', 'string'],
            Factory::API_KEY => ['nullable', 'string', 'max:255'],
            Factory::API_URL => ['nullable', 'string', 'max:255'],
            Factory::IS_ACTIVE => ['boolean'],
            // svg عمداً نیست: فایل روی دیسک public می‌نشیند و یک SVG حاوی <script>
            // تبدیل به XSS ذخیره‌شده می‌شود (با FILESYSTEM_PUBLIC_DRIVER=local — که
            // پیش‌فرضِ .env.example است — همان مبدأِ خودِ اپ سرو می‌شود و CSP هم
            // script-src 'self' دارد، پس جلویش را نمی‌گیرد). این مسیر از همه بدتر بود
            // چون کاربرِ عادی (تأمین‌کنندهٔ تأییدشده) به آن دسترسی دارد، نه فقط ادمین.
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
