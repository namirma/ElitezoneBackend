<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePriceSettingsRequest;
use App\Models\PriceSetting;
use Illuminate\Http\JsonResponse;

/**
 * پنل مدیریت — پارامترهای مالیِ تسویه (price_settings).
 *
 * هر پارامتر با برچسب/توضیح/پیش‌فرض/بازه از `PriceSetting::catalog()` برمی‌گردد؛
 * مقدارِ ذخیره‌نشده `value = null` است (مصرف‌کننده‌ها پیش‌فرض را می‌خوانند).
 * کشِ `price_setting_*` را `PriceSetting::booted()` روی save باطل می‌کند.
 */
class AdminPriceSettingController extends Controller
{
    // GET /admin/price-settings
    public function index(): JsonResponse
    {
        return success(['settings' => $this->rows()], __('global.received_successfully'));
    }

    // PUT /admin/price-settings
    public function update(UpdatePriceSettingsRequest $request): JsonResponse
    {
        $catalog = PriceSetting::catalog();
        $before = PriceSetting::query()->pluck(PriceSetting::VALUE, PriceSetting::CODE)->all();
        $changed = [];

        foreach ($request->validated('settings') as $code => $value) {
            $value = $catalog[$code]['unit'] === 'boolean'
                ? (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false')
                : (string) (float) $value;

            PriceSetting::updateOrCreate(
                [PriceSetting::CODE => $code],
                [
                    PriceSetting::NAME => $catalog[$code]['label'],
                    PriceSetting::DESCRIPTION => $catalog[$code]['description'],
                    PriceSetting::VALUE => $value,
                    PriceSetting::UPDATED_BY => auth()->id(),
                ],
            );
            $changed[$code] = ['before' => $before[$code] ?? null, 'after' => $value];
        }

        activity()
            ->causedBy(auth()->user())
            ->withProperties(['changes' => $changed])
            ->log('پارامترهای مالی بروزرسانی شد.');

        return success(['settings' => $this->rows()], 'پارامترهای مالی ذخیره شد.');
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $stored = PriceSetting::query()->with('updatedBy')->get()->keyBy(PriceSetting::CODE);
        $rows = [];

        foreach (PriceSetting::catalog() as $code => $meta) {
            /** @var PriceSetting|null $row */
            $row = $stored->get($code);
            $by = $row?->updatedBy;

            $rows[] = [
                'key' => $code,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'value' => $row?->{PriceSetting::VALUE},
                'default' => $meta['default'],
                'unit' => $meta['unit'],
                'min' => $meta['min'],
                'max' => $meta['max'],
                'updated_at' => $row?->{PriceSetting::UPDATED_AT}?->toISOString(),
                'updated_by' => $by ? ['id' => $by->id, 'name' => $by->full_name] : null,
            ];
        }

        return $rows;
    }
}
