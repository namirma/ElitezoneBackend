<?php

namespace App\Http\Requests\Admin;

use App\Models\Faq;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ساخت پرسش متداول جدید توسط ادمین.
 */
class StoreFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            Faq::QUESTION => ['required', 'string', 'max:500'],
            Faq::ANSWER => ['required', 'string'],
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
