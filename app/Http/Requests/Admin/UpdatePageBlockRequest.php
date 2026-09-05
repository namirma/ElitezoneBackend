<?php

namespace App\Http\Requests\Admin;

use App\Models\PageBlock;
use App\Services\Content\PageBlockSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * قواعدِ `data` از `PageBlockSchema` می‌آیند و این‌جا دستی تکرار نمی‌شوند —
 * وگرنه فرمِ پنل و اعتبارسنجی دو قرارداد می‌شدند.
 */
class UpdatePageBlockRequest extends FormRequest
{
    public function rules(): array
    {
        $schema = app(PageBlockSchema::class);
        $pageBlock = $this->route('pageBlock');
        $type = (string) ($this->input('type') ?? ($pageBlock instanceof PageBlock ? $pageBlock->{PageBlock::TYPE} : null));

        return array_merge([
            'page' => ['sometimes', Rule::in(PageBlock::PAGES)],
            'type' => ['sometimes', Rule::in($schema->types())],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'data' => ['sometimes', 'array'],
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
