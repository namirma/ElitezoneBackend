<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Profile\EliteYARVerificationMethods;
use App\Services\Sales\SalesSettings;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * پنل مدیریت — تنظیمات key-value سایت (ماژول ۴ نیازمندی فرانت BackOffice).
 *
 * GET همه تنظیمات را به صورت map برمی‌گرداند؛ PUT مجموعه‌ای از کلیدها را upsert می‌کند.
 * کش settings:all با هر save به صورت خودکار flush می‌شود (Setting::booted).
 */
class AdminSettingController extends Controller
{
    // GET /admin/settings
    public function index(): JsonResponse
    {
        return success(Setting::allCached(), __('admin.settings.list_received'));
    }

    // PUT /admin/settings
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                'settings' => ['required', 'array', 'min:1'],
                'settings.*' => ['nullable', 'string', 'max:65000'],
            ],
            [
                'settings.required' => __('admin.settings.settings_required'),
                'settings.array' => __('admin.settings.settings_invalid'),
            ]
        );

        // فقط کلیدهای whitelist‌شده — غلط تایپی نباید کلید زامبی بسازد
        $allowed = config('settings.allowed_keys', []);
        $unknown = array_diff(array_keys($validated['settings']), $allowed);
        if ($unknown !== []) {
            return error(
                __('admin.settings.unknown_keys', ['keys' => implode(', ', $unknown)]),
                ['settings' => array_values($unknown)],
                422
            );
        }

        // پارامترهای مسیر فروش تایپ‌دارند، برخلاف بقیه‌ی تنظیمات که رشته‌ی آزادند.
        // بدون این، یک `park_time_minutes = "abc"` بی‌صدا ذخیره می‌شد و بعداً به
        // صفر cast می‌شد — یعنی سبدها بلافاصله منقضی می‌شدند.
        if ($failure = $this->validateSalesSettings($validated['settings'])) {
            return $failure;
        }

        foreach ($validated['settings'] as $key => $value) {
            Setting::updateOrCreate(
                [Setting::KEY => (string) $key],
                [Setting::VALUE => $value]
            );
        }

        // Audit: چه کسی کدام کلیدها را تغییر داد (مقادیر هم برای بازسازی ثبت می‌شود)
        activity()
            ->causedBy(auth()->user())
            ->withProperties(['keys' => array_keys($validated['settings']), 'values' => $validated['settings']])
            ->log('تنظیمات سایت بروزرسانی شد.');

        return success(Setting::allCached(), __('admin.settings.updated'));
    }

    /**
     * اعتبارسنجی تایپ‌دارِ شش پارامتر فروش، به‌علاوه‌ی قاعده‌ی متقاطعِ بند ۹.
     *
     * `reserve_value ≤ shaparak_value` در برابر مقدارِ **مؤثر** سنجیده می‌شود:
     * اگر ادمین هر دو را با هم بفرستد مقدارِ تازه‌ی سقف ملاک است، وگرنه مقدارِ
     * ذخیره‌شده‌ی فعلی. سنجیدن در برابرِ مقدارِ ذخیره‌شده به‌تنهایی یعنی
     * فرستادنِ هر دو در یک درخواست همیشه رد می‌شد.
     */
    private function validateSalesSettings(array $settings): ?JsonResponse
    {
        // روش‌های تأیید الیت یار هم تایپ‌دارند («1»/«0») — همان مسیر، بدون کنترلرِ دوم.
        $rules = SalesSettings::validationRules() + EliteYARVerificationMethods::validationRules() + [
            // سقف = عمرِ توکنِ Sanctum: تایمرِ بی‌کاریِ بلندتر از آن دروغ می‌گفت (توکن زودتر می‌میرد).
            'session_idle_logout_minutes' => config('sanctum.expiration')
                ? ['integer', 'min:1', 'max:'.(int) config('sanctum.expiration')]
                : ['integer', 'min:1'],
        ];
        // واحدِ پولِ هر اپ (BE3) — غلطِ تایپی نباید بی‌صدا به «ریال» برگردد.
        foreach (Money::APPS as $app) {
            $rules[Money::settingKey($app)] = ['in:'.implode(',', Money::UNITS)];
        }
        $subject = array_intersect_key($settings, $rules);

        if ($subject === []) {
            return null;
        }

        $validator = Validator::make(
            $subject,
            array_intersect_key($rules, $subject)
        );

        $validator->after(function ($validator) use ($settings) {
            if (! array_key_exists('reserve_value', $settings)) {
                return;
            }

            $sales = app(SalesSettings::class);
            $cap = array_key_exists('shaparak_value', $settings)
                ? (float) $settings['shaparak_value']
                : $sales->shaparakValue();

            if ((float) $settings['reserve_value'] > $cap) {
                $validator->errors()->add(
                    'reserve_value',
                    __('admin.settings.reserve_value_above_shaparak')
                );
            }
        });

        if ($validator->fails()) {
            return error(
                __('admin.settings.invalid_values'),
                $validator->errors()->toArray(),
                422
            );
        }

        return null;
    }
}
