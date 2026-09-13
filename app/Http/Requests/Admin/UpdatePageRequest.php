<?php

namespace App\Http\Requests\Admin;

use App\Models\Page;
use App\Services\Content\PageBlockSchema;
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
        $isBlockPage = $page instanceof Page && $page->{Page::HAS_BLOCKS};

        return array_merge([
            Page::TITLE => ['sometimes', 'required', 'string', 'max:255'],
            // اسلاگِ صفحه‌ی بلوکی قفل است: `page_blocks.page` یک رشته است، نه
            // کلیدِ خارجی، پس تغییرِ اسلاگ هر بلوکِ آن صفحه را بی‌صدا یتیم
            // می‌کند — صفحه خالی می‌شود و هیچ خطایی هم نمی‌دهد.
            //
            // ⚠️ `prohibited` باید **تنها** قاعده باشد. اولین نسخه‌اش
            // `[$isBlockPage ? 'prohibited' : 'sometimes', 'required', …]` بود،
            // یعنی روی صفحه‌ی بلوکی هم‌زمان «ممنوع» و «الزامی» — یک شرطِ
            // ارضاناپذیر که **هر** ویرایشی روی آن صفحه را ۴۲۲ می‌کرد، از جمله
            // ذخیره‌ی استایلِ صفحه که اصلاً اسلاگ نمی‌فرستد. تست سبز بود و
            // کنسول تمیز؛ فقط یک PUTِ واقعی نشانش داد.
            Page::SLUG => $isBlockPage
                ? ['prohibited']
                : [
                    'sometimes', 'required', 'string', 'max:255', 'regex:/^[\pL\pN\-_]+$/u',
                    Rule::unique('pages', 'slug')->ignore($page?->id),
                ],
            // صفحه‌ی بلوکی بدنه ندارد؛ صفحه‌ی متنی نباید بتواند خالی شود.
            Page::BODY => $isBlockPage
                ? ['sometimes', 'nullable', 'string']
                : ['sometimes', 'required', 'string'],
            Page::IS_ACTIVE => ['nullable', 'boolean'],
            Page::HAS_BLOCKS => ['nullable', 'boolean'],
        ], PageBlockSchema::pageStyleRules());
    }

    public function messages(): array
    {
        return [
            'title.required' => __('admin.pages.title_required'),
            'slug.required' => __('admin.pages.slug_required'),
            'slug.unique' => __('admin.pages.slug_unique'),
            'slug.regex' => __('admin.pages.slug_invalid'),
            'slug.prohibited' => __('admin.pages.slug_locked_for_block_page'),
            'body.required' => __('admin.pages.body_required'),
        ];
    }
}
