<?php

namespace App\Http\Requests\Admin;

use App\Models\Page;
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
        return [
            Page::TITLE => ['required', 'string', 'max:255'],
            Page::SLUG => ['required', 'string', 'max:255', 'unique:pages,slug', 'regex:/^[\pL\pN\-_]+$/u'],
            Page::BODY => ['required', 'string'],
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
