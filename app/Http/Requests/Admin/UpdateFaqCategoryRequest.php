<?php

namespace App\Http\Requests\Admin;

use App\Models\FaqCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ویرایش دسته‌بندی سوالات متداول توسط ادمین.
 */
class UpdateFaqCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // نامِ پارامتر باید عینِ `routes/api/admin.php` باشد: `{faqCategory}`.
        // با `faq_category` این `null` برمی‌گشت، `ignore(null)` می‌شد، و ویرایشِ
        // یک دسته بدونِ تغییرِ slug با «تکراری است» رد می‌شد.
        $category = $this->route('faqCategory');
        $id = $category instanceof FaqCategory ? $category->getKey() : null;

        return [
            FaqCategory::TITLE => ['sometimes', 'required', 'string', 'max:150'],
            FaqCategory::SLUG => [
                'sometimes', 'required', 'string', 'max:150', 'regex:/^[\pL\pN\-_]+$/u',
                Rule::unique('faq_categories', 'slug')->ignore($id)->withoutTrashed(),
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
