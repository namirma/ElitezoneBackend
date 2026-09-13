<?php

namespace App\Http\Requests\Admin;

use App\Models\Page;
use App\Services\Content\PageBlockSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * قواعدِ `data` از `PageBlockSchema` می‌آیند و این‌جا دستی تکرار نمی‌شوند —
 * وگرنه فرمِ پنل و اعتبارسنجی دو قرارداد می‌شدند.
 */
class StorePageBlockRequest extends FormRequest
{
    public function rules(): array
    {
        $schema = app(PageBlockSchema::class);
        // `(string) $this->input('type')` روی یک آرایه (مثلِ `type: []`) هشدارِ
        // PHP «Array to string conversion» می‌دهد که هندلرِ لاراول به استثنا
        // تبدیلش می‌کند — یعنی ۵۰۰ به‌جای ۴۲۲. مقدارِ غیررشته‌ای همان‌طور که
        // هست رد می‌شود تا `Rule::in()` پایین با یک ۴۲۲ معمولی جوابش بدهد.
        $rawType = $this->input('type');
        $type = is_string($rawType) ? $rawType : '';

        return array_merge([
            'page' => ['required', Rule::in(Page::blockPageSlugs())],
            'type' => ['required', Rule::in($schema->types())],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'data' => ['required', 'array'],
        ], $schema->rulesFor($type));
    }

    public function messages(): array
    {
        return [
            'type.in' => __('page_blocks.unknown_type'),
            'page.in' => __('page_blocks.unknown_page'),
        ];
    }
}
