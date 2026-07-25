<?php

namespace App\Http\Requests\Admin;

use App\Models\Badge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * بروزرسانی نشان توسط ادمین. فیلدها sometimes هستند تا بتوان فقط بخشی را تغییر داد
 * (مثلاً فقط جایگزینی آیکون). یکتایی code با نادیده‌گرفتن ردیف خودی چک می‌شود.
 */
class UpdateBadgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $badge = $this->route('badge');
        $id = $badge instanceof Badge ? $badge->id : null;

        return [
            Badge::NAME => ['sometimes', 'required', 'string', 'max:255'],
            Badge::CODE => ['sometimes', 'required', 'string', 'max:255', Rule::unique('badges', 'code')->ignore($id)],
            // svg عمداً مجاز نیست: آیکون روی دیسک public می‌نشیند و از همان origin
            // سرو می‌شود، پس یک SVG حاوی <script> برابر است با XSS ذخیره‌شده
            // (CSP فعلی با script-src 'self' جلوی اسکریپتِ هم‌مبدأ را نمی‌گیرد).
            // StoreBadgeRequest هم همین لیست را دارد — این دو باید یکی بمانند.
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
