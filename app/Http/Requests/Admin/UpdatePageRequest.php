<?php

namespace App\Http\Requests\Admin;

use App\Models\Page;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ویرایش صفحه استاتیک — یکتایی slug با نادیده‌گرفتن رکورد جاری.
 */
class UpdatePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $page = $this->route('page');

        return [
            Page::TITLE => ['sometimes', 'required', 'string', 'max:255'],
            Page::SLUG => [
                'sometimes', 'required', 'string', 'max:255', 'regex:/^[\pL\pN\-_]+$/u',
                Rule::unique('pages', 'slug')->ignore($page?->id),
            ],
            Page::BODY => ['sometimes', 'required', 'string'],
            Page::IS_ACTIVE => ['nullable', 'boolean'],
        ];
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
