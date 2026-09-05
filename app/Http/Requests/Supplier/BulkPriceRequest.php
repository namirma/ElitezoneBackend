<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

/**
 * آپلودِ گروهیِ قیمت.
 *
 * ⚠️ ورودی **آرایه‌ی JSON** است نه فایلِ CSV، و این یک تصمیم است نه میان‌بر:
 * فایلِ اکسلِ فارسی سه تلهٔ شناخته‌شده دارد (BOM، جداکننده‌ی `;` در ویندوزِ
 * فارسی، و انکودینگِ غیر‌UTF-8) که هر سه در مرورگر — جایی که فایل واقعاً هست —
 * بهتر حل می‌شوند تا روی سرور. مهم‌تر: با پارسِ سمتِ کلاینت، خطای «سطر ۱۲
 * کارخانه‌اش مالِ شما نیست» را می‌شود کنارِ همان سطر در جدول نشان داد.
 *
 * پس فرانت CSV را می‌خواند و ردیف‌ها را اینجا می‌فرستد؛ پاسخِ خطا هم به همان
 * **شماره‌ی ردیف** کلید می‌خورد.
 */
class BulkPriceRequest extends FormRequest
{
    /** سقفِ هر درخواست — هم حافظه، هم طولِ تراکنش. */
    public const MAX_ROWS = 500;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.product_variant_id' => ['required', 'integer'],
            'rows.*.factory_id' => ['required', 'integer'],
            'rows.*.factory_location_id' => ['required', 'integer'],
            'rows.*.general_price' => ['required', 'numeric', 'min:0'],
            'rows.*.special_price' => ['nullable', 'numeric', 'min:0'],
            'rows.*.golden_figure' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required' => __('supplier.bulk.rows_required'),
            'rows.max' => __('supplier.bulk.too_many_rows', ['max' => self::MAX_ROWS]),
        ];
    }
}
