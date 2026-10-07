<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Product\PriceFilterResource;
use App\Models\PriceFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD فیلترهای قیمت (PriceFilter).
 *
 * هر فیلتر یک تعدیل قیمت است (درصدی یا مبلغ ثابت) که به یک PriceFilterType تعلق دارد
 * و از طریق pivot به PriceFilterCategoryها وصل می‌شود. کش قیمت‌گذاری توسط
 * PriceFilter::booted() خودکار فلاش می‌شود.
 */
class AdminPriceFilterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'price_filter_type_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $filters = PriceFilter::query()
            ->when($request->filled('price_filter_type_id'), fn ($q) => $q->where(PriceFilter::PRICE_FILTER_TYPE_ID, $request->integer('price_filter_type_id')))
            ->when($request->has('is_active'), fn ($q) => $q->where(PriceFilter::IS_ACTIVE, $request->boolean('is_active')))
            ->with('filterType')
            ->orderBy(PriceFilter::SORT_ORDER)
            ->orderBy(PriceFilter::NAME)
            ->paginate($this->perPage(100));

        return success(PriceFilterResource::collection($filters), __('admin.filters.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), $this->messages());
        // ستون پیش‌فرضِ `true` دارد — فیلترِ بی‌`is_active` فعال ساخته می‌شود.
        $this->assertMayActivate((bool) ($data[PriceFilter::IS_ACTIVE] ?? true), false);

        $filter = PriceFilter::create($data);

        return created(new PriceFilterResource($filter), __('admin.filters.created'));
    }

    public function update(Request $request, PriceFilter $filter): JsonResponse
    {
        $data = $request->validate($this->rules($filter->id), $this->messages());
        $this->assertMayActivate((bool) ($data[PriceFilter::IS_ACTIVE] ?? $filter->{PriceFilter::IS_ACTIVE}), (bool) $filter->{PriceFilter::IS_ACTIVE});

        $filter->update($data);

        return success(new PriceFilterResource($filter), __('admin.filters.updated'));
    }

    public function destroy(PriceFilter $filter): JsonResponse
    {
        $filter->delete();

        return success(null, __('admin.filters.deleted'));
    }

    public function toggle(PriceFilter $filter): JsonResponse
    {
        $this->assertMayActivate(! $filter->{PriceFilter::IS_ACTIVE}, (bool) $filter->{PriceFilter::IS_ACTIVE});

        $filter->update([PriceFilter::IS_ACTIVE => ! $filter->{PriceFilter::IS_ACTIVE}]);

        $message = $filter->{PriceFilter::IS_ACTIVE}
            ? __('admin.filters.toggled_active')
            : __('admin.filters.toggled_inactive');

        return success(new PriceFilterResource($filter), $message);
    }

    /**
     * مدیریت، دورِ هفتم، پرسشِ ۷-۲: «قیمتِ فروش برای همه برابرِ قیمتِ تأمین‌کننده». فقط **روشن‌کردن** رد می‌شود؛
     * خاموش‌کردن، ویرایش و حذف آزاد می‌ماند تا فیلترِ فعالِ قدیمی هم قابلِ جمع‌کردن باشد.
     */
    private function assertMayActivate(bool $willBeActive, bool $isActive): void
    {
        if ($willBeActive && ! $isActive && ! config('features.role_price_filters')) {
            throw new BusinessException(__('admin.filters.role_price_filters_off'), 422);
        }
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return ['amount.min' => __('admin.filters.discount_not_allowed')];
    }

    private function rules(?int $ignoreId = null): array
    {
        $req = $ignoreId ? 'sometimes' : 'required';

        return [
            PriceFilter::PRICE_FILTER_TYPE_ID => [$req, 'integer', 'exists:price_filter_types,id'],
            PriceFilter::NAME => [$req, 'string', 'max:255'],
            PriceFilter::CODE => [$req, 'string', 'max:255', Rule::unique('price_filters', 'code')->ignore($ignoreId)],
            // «هیچ تخفیفِ دیگری در سیستم وجود ندارد» (مدیریت، دورِ پنجم): فیلترِ قیمت فقط افزایش است؛
            // تخفیفِ نقش‌محور با کوپن از «کیف پول کوپن‌های تخفیف» داده می‌شود.
            PriceFilter::AMOUNT => [$req, 'numeric', 'min:0'],
            PriceFilter::IS_PERCENTAGE => ['boolean'],
            PriceFilter::IS_ACTIVE => ['boolean'],
            PriceFilter::SORT_ORDER => ['nullable', 'integer', 'min:0'],
        ];
    }
}
