<?php

namespace App\Http\Requests\Admin;

use App\Models\Page;
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

        // `(string) $this->input('type')` روی یک آرایه (مثلِ `type: []`)
        // هشدارِ PHP «Array to string conversion» می‌دهد که هندلرِ لاراول به
        // استثنا تبدیلش می‌کند — یعنی ۵۰۰ به‌جای ۴۲۲. مقدارِ غیررشته‌ای همان‌طور
        // که هست رد می‌شود تا `Rule::in()` پایین با یک ۴۲۲ معمولی جوابش بدهد؛
        // فقط وقتی کلاً فرستاده نشده (`null`) نوعِ ذخیره‌شده‌ی روی ردیف جایگزینش می‌شود.
        $rawType = $this->input('type');
        $type = match (true) {
            is_string($rawType) => $rawType,
            $rawType === null && $pageBlock instanceof PageBlock => (string) $pageBlock->{PageBlock::TYPE},
            default => '',
        };

        $dataRules = $schema->rulesFor($type);

        if ($this->missing('data')) {
            // `data` روی این ریکوئست «sometimes» است — یعنی حذف‌کردنش باید
            // واقعاً یعنی «چیزی درباره‌اش اعتبارسنجی نشود». اما قواعدِ ادغام‌شده
            // از اسکیما implicit-required هستند (`data.title` و مشابه)، پس
            // بدونِ این گارد یک `PUT {"sort_order": 3}` روی `data.title` شکست
            // می‌خورد — با اینکه اصلاً `data` نفرستاده. فیلدهای اجباری وقتی
            // `data` واقعاً فرستاده می‌شود دست‌نخورده می‌مانند: `data` کلِ
            // ستونِ JSON را جایگزین می‌کند، پس اختیاری‌کردنِ آن‌ها یعنی ذخیره‌ی
            // خاموشِ یک بلوکِ ناقص.
            $dataRules = [];
        }

        return array_merge([
            'page' => ['sometimes', Rule::in(Page::blockPageSlugs())],
            'type' => ['sometimes', Rule::in($schema->types())],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'data' => ['sometimes', 'array'],
        ], $dataRules);
    }

    public function messages(): array
    {
        return [
            'type.in' => __('page_blocks.unknown_type'),
            'page.in' => __('page_blocks.unknown_page'),
        ];
    }
}
