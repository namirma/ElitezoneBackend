<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Supplier\FactoryFeatureResource;
use App\Models\FactoryFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD ویژگی‌های بازاریابیِ کارخانه (FactoryFeature).
 *
 * هر ویژگی (آیکون + متنِ کوتاه) به یک کارخانه تعلق دارد و روی صفحه‌ی سفارش
 * (فوترِ «محاسبه کل هزینه حمل») و کارتِ محصولِ همان کارخانه در سایتِ اصلی
 * نمایش داده می‌شود. آیکون از یک کتابخانه‌ی ثابت انتخاب می‌شود، نه آپلودِ آزاد.
 */
class AdminFactoryFeatureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'factory_id' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::in(FactoryFeature::CATEGORIES)],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $features = FactoryFeature::query()
            ->when($request->filled('factory_id'), fn ($q) => $q->where(FactoryFeature::FACTORY_ID, $request->integer('factory_id')))
            ->when($request->filled('category'), fn ($q) => $q->where(FactoryFeature::CATEGORY, $request->string('category')))
            ->when($request->has('is_active'), fn ($q) => $q->where(FactoryFeature::IS_ACTIVE, $request->boolean('is_active')))
            ->orderBy(FactoryFeature::FACTORY_ID)
            ->orderBy(FactoryFeature::CATEGORY)
            ->orderBy(FactoryFeature::ORDER)
            ->paginate($this->perPage(100));

        return success(FactoryFeatureResource::collection($features), __('admin.factory_features.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $feature = FactoryFeature::create($request->validate($this->rules($request)));

        return created(new FactoryFeatureResource($feature), __('admin.factory_features.created'));
    }

    public function update(Request $request, FactoryFeature $factoryFeature): JsonResponse
    {
        $factoryFeature->update($request->validate($this->rules($request, $factoryFeature)));

        return success(new FactoryFeatureResource($factoryFeature), __('admin.factory_features.updated'));
    }

    public function destroy(FactoryFeature $factoryFeature): JsonResponse
    {
        $factoryFeature->delete();

        return success(null, __('admin.factory_features.deleted'));
    }

    public function toggle(FactoryFeature $factoryFeature): JsonResponse
    {
        $factoryFeature->update([FactoryFeature::IS_ACTIVE => ! $factoryFeature->{FactoryFeature::IS_ACTIVE}]);

        $message = $factoryFeature->{FactoryFeature::IS_ACTIVE}
            ? __('admin.factory_features.toggled_active')
            : __('admin.factory_features.toggled_inactive');

        return success(new FactoryFeatureResource($factoryFeature), $message);
    }

    /**
     * آیکون باید به همان دسته‌ای تعلق داشته باشد که رکورد دارد — وگرنه مثلاً
     * آیکونِ «حمل در لحظه» می‌توانست روی کارتِ محصول بنشیند و تفکیکِ دو دسته
     * بی‌معنا شود. دسته‌ی مؤثر: مقدارِ ارسالی، وگرنه مقدارِ فعلیِ رکورد.
     */
    private function rules(Request $request, ?FactoryFeature $existing = null): array
    {
        $partial = $existing !== null;
        $req = $partial ? 'sometimes' : 'required';

        // نه `?->` داخل زنجیرهٔ `??`: larastan روی برنچِ L13 آن را «بی‌مورد»
        // خطا می‌گیرد. شرطِ صریح روی هر دو برنچ یکسان می‌گذرد و همان معنا را دارد.
        $existingCategory = $existing !== null ? $existing->{FactoryFeature::CATEGORY} : null;

        $category = $request->input(FactoryFeature::CATEGORY)
            ?? $existingCategory
            ?? FactoryFeature::CATEGORY_PRODUCT;

        return [
            FactoryFeature::FACTORY_ID => [$req, 'integer', 'exists:factories,id'],
            FactoryFeature::CATEGORY => [$req, Rule::in(FactoryFeature::CATEGORIES)],
            FactoryFeature::ICON_KEY => [$req, Rule::in(FactoryFeature::iconKeysFor($category))],
            FactoryFeature::LABEL => [$req, 'string', 'max:255'],
            FactoryFeature::ORDER => ['nullable', 'integer', 'min:0'],
            FactoryFeature::IS_ACTIVE => ['boolean'],
        ];
    }
}
