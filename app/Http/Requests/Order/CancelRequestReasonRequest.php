<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * «دلیل» در جریانِ لغوِ دوطرفه — پرسش ۴ مدیریت.
 *
 * یک FormRequest برای هر سه نوشتنِ دلیل‌دار (ثبتِ درخواست، ردِ طرفِ مقابل،
 * تصمیمِ بک‌آفیس): همان `min:10` لغوِ اجباری، چون «دلیل» باید جمله باشد وگرنه
 * کسی که باید تصمیم بگیرد چیزی برای خواندن ندارد.
 */
class CancelRequestReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => __('orders.cancel_request.reason_required'),
            'reason.min' => __('orders.cancel_request.reason_too_short'),
        ];
    }
}
