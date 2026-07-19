<?php

namespace App\Http\Requests\Admin;

use App\Models\Banner;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ساخت بنر جدید توسط ادمین.
 *
 * authorize() همیشه true است چون روت پشت middleware [auth:sanctum, role:admin] قرار دارد.
 */
class StoreBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            Banner::TITLE => ['required', 'string', 'max:255'],
            Banner::IMAGE => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
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
            'image.required' => __('admin.banners.image_required'),
            'image.mimes' => __('admin.banners.image_invalid'),
            'image.max' => __('admin.banners.image_too_large'),
        ];
    }
}
