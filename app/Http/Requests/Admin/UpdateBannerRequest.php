<?php

namespace App\Http\Requests\Admin;

use App\Models\Banner;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ویرایش بنر — تصویر اختیاری است (فقط هنگام تعویض ارسال می‌شود).
 */
class UpdateBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            Banner::TITLE => ['sometimes', 'required', 'string', 'max:255'],
            Banner::IMAGE => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            Banner::LINK => ['nullable', 'string', 'max:2048'],
            Banner::POSITION => ['nullable', 'string', 'max:100'],
            Banner::SORT_ORDER => ['nullable', 'integer', 'min:0'],
            Banner::IS_ACTIVE => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => __('admin.banners.title_required'),
            'image.mimes' => __('admin.banners.image_invalid'),
            'image.max' => __('admin.banners.image_too_large'),
        ];
    }
}
