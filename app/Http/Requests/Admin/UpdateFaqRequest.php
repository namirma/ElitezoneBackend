<?php

namespace App\Http\Requests\Admin;

use App\Models\Faq;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ویرایش پرسش متداول.
 */
class UpdateFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            Faq::FAQ_CATEGORY_ID => ['nullable', 'integer', 'exists:faq_categories,id'],
            Faq::QUESTION => ['sometimes', 'required', 'string', 'max:500'],
            Faq::ANSWER => ['sometimes', 'required', 'string'],
            Faq::CATEGORY => ['nullable', 'string', 'max:100'],
            Faq::SORT_ORDER => ['nullable', 'integer', 'min:0'],
            Faq::IS_ACTIVE => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'question.required' => __('admin.faq.question_required'),
            'answer.required' => __('admin.faq.answer_required'),
        ];
    }
}
