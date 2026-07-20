<?php

namespace App\Http\Requests\Admin;

use App\Models\Article;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ویرایش مقاله — کاور اختیاری؛ یکتایی slug با نادیده‌گرفتن رکورد جاری.
 */
class UpdateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $article = $this->route('article');

        return [
            Article::TITLE => ['sometimes', 'required', 'string', 'max:255'],
            Article::SLUG => [
                'sometimes', 'required', 'string', 'max:255', 'regex:/^[\pL\pN\-_]+$/u',
                Rule::unique('articles', 'slug')->ignore($article?->id),
            ],
            Article::EXCERPT => ['nullable', 'string', 'max:500'],
            Article::BODY => ['sometimes', 'required', 'string'],
            Article::COVER => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            Article::IS_ACTIVE => ['nullable', 'boolean'],
            Article::PUBLISHED_AT => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => __('admin.articles.title_required'),
            'slug.required' => __('admin.articles.slug_required'),
            'slug.unique' => __('admin.articles.slug_unique'),
            'slug.regex' => __('admin.articles.slug_invalid'),
            'body.required' => __('admin.articles.body_required'),
            'cover.mimes' => __('admin.articles.cover_invalid'),
            'cover.max' => __('admin.articles.cover_too_large'),
        ];
    }
}
