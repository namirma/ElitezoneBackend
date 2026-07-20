<?php

namespace App\Http\Requests\Admin;

use App\Models\Article;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ساخت مقاله جدید توسط ادمین.
 */
class StoreArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            Article::TITLE => ['required', 'string', 'max:255'],
            Article::SLUG => ['required', 'string', 'max:255', 'unique:articles,slug', 'regex:/^[\pL\pN\-_]+$/u'],
            Article::EXCERPT => ['nullable', 'string', 'max:500'],
            Article::BODY => ['required', 'string'],
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
