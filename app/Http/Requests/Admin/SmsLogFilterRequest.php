<?php

namespace App\Http\Requests\Admin;

use App\Models\SmsLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * فیلترهای گزارشِ ارسال. تاریخ‌ها میلادیِ `Y-m-d` هستند — پنل پیش از ارسال
 * جلالی را تبدیل می‌کند (فیلترِ `date` در CrudSection)؛ این ورودیِ جلالی نیست.
 */
class SmsLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile' => ['nullable', 'string', 'max:20'],
            'key' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(SmsLog::STATUSES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
