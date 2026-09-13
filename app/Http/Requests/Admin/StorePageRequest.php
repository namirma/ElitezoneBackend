<?php

namespace App\Http\Requests\Admin;

use App\Models\Page;
use App\Services\Content\PageBlockSchema;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ساخت صفحه استاتیک جدید توسط ادمین.
 */
class StorePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            Page::TITLE => ['required', 'string', 'max:255'],
            Page::SLUG => ['required', 'string', 'max:255', 'unique:pages,slug', 'regex:/^[\pL\pN\-_]+$/u'],
            // `required_if` و نه `required`: صفحه‌ی بلوکی بدنه‌ی متنی **ندارد** —
            // محتوایش از `page_blocks` می‌آید. با قاعده‌ی قبلی ادمین اصلاً
            // نمی‌توانست صفحه‌ی بلوکیِ تازه بسازد، که کلِ هدفِ فازِ ۳ بود.
            Page::BODY => ['required_if:'.Page::HAS_BLOCKS.',false,0,null', 'nullable', 'string'],
            Page::IS_ACTIVE => ['nullable', 'boolean'],
            Page::HAS_BLOCKS => ['nullable', 'boolean'],
            // `route` را ادمین نمی‌فرستد: صفحه‌ی ساخته‌شده همیشه زیرِ `/p/{slug}`
            // می‌نشیند و مسیرِ اختصاصی فقط برای سه صفحه‌ی اولیه‌ی سیدشده است.
            // اگر اجازه‌ی نوشتنش داده شود، ادمین می‌تواند `/` را بدزدد.
        ], PageBlockSchema::pageStyleRules());
    }

    public function messages(): array
    {
        return [
            'title.required' => __('admin.pages.title_required'),
            'slug.required' => __('admin.pages.slug_required'),
            'slug.unique' => __('admin.pages.slug_unique'),
            'slug.regex' => __('admin.pages.slug_invalid'),
            'body.required' => __('admin.pages.body_required'),
        ];
    }
}
