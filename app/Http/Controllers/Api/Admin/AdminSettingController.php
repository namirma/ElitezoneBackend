<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        foreach ($validated['settings'] as $key => $value) {
            Setting::updateOrCreate(
                [Setting::KEY => (string) $key],
                [Setting::VALUE => $value]
            );
        }

        return success(Setting::allCached(), __('admin.settings.updated'));
    }
}
