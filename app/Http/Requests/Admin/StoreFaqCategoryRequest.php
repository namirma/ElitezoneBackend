<?php

namespace App\Http\Requests\Admin;

use App\Models\FaqCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ساخت دسته‌بندی سوالات متداول توسط ادمین.
 */
class StoreFaqCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            FaqCategory::TITLE => ['required', 'string', 'max:150'],
            // نامک فارسی مجاز است (مثل بقیه محتوا) — هرگز Str::slug سمت سرور
            FaqCategory::SLUG => [
                'required', 'string', 'max:150', 'regex:/^[\pL\pN\-_]+$/u',
                Rule::unique('faq_categories', 'slug')->withoutTrashed(),
            ],
            FaqCategory::SUBTITLE => ['nullable', 'string', 'max:500'],
            FaqCategory::SORT_ORDER => ['nullable', 'integer', 'min:0'],
            FaqCategory::IS_ACTIVE => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => __('admin.faq_categories.title_required'),
            'slug.required' => __('admin.faq_categories.slug_required'),
            'slug.unique' => __('admin.faq_categories.slug_unique'),
            'slug.regex' => __('admin.faq_categories.slug_invalid'),
        ];
    }
}
