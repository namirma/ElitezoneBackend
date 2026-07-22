<?php

namespace App\Http\Requests\Admin;

use App\Models\Badge;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ساخت نشان جدید توسط ادمین.
 *
 * authorize() همیشه true است چون روت پشت middleware [auth:sanctum, role:admin] قرار دارد.
 */
class StoreBadgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            Badge::NAME => ['required', 'string', 'max:255'],
            Badge::CODE => ['required', 'string', 'max:255', 'unique:badges,code'],
            // svg حذف شد: SVG می‌تواند حاوی اسکریپت باشد (XSS ذخیره‌شده اگر مستقیم رندر شود).
            Badge::ICON => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('admin.badges.name_required'),
            'code.required' => __('admin.badges.code_required'),
            'code.unique' => __('admin.badges.code_unique'),
            'icon.mimes' => __('admin.badges.icon_invalid'),
            'icon.max' => __('admin.badges.icon_too_large'),
        ];
    }
}
