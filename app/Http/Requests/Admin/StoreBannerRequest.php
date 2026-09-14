<?php

namespace App\Http\Requests\Admin;

use App\Models\Banner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            Banner::SUBTITLE => ['nullable', 'string', 'max:255'],
            Banner::IMAGE => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            Banner::LINK => ['nullable', 'string', 'max:2048'],
            // جایگاه اجباری است و فقط از فهرستِ مدل: بنرِ بی‌جایگاه هیچ‌جا رندر
            // نمی‌شود و «ذخیره‌ی موفقِ بی‌اثر» همان چیزی است که قاعده‌ی صداقت ردش می‌کند.
            Banner::POSITION => ['required', 'string', Rule::in(array_keys(Banner::POSITIONS))],
            // بنرِ دسته‌بندی بدونِ دسته یتیم است — صفحه‌ی دسته‌بندی نمی‌داند مالِ کیست.
            Banner::CATEGORY_ID => ['nullable', 'required_if:'.Banner::POSITION.','.Banner::POSITION_CATEGORY, 'integer', Rule::exists('product_categories', 'id')],
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
            'category_id.required_if' => __('admin.banners.category_required'),
        ];
    }
}
