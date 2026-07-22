<?php

namespace App\Http\Requests\Admin;

use App\Models\WalletTransaction;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedStatuses = [
            WalletTransaction::STATUS_CONFIRMED,
            WalletTransaction::STATUS_REJECTED,
        ];

        return [
            'status' => 'required|in:'.implode(',', $allowedStatuses),
            'note' => 'nullable|string|max:255',
            // مبلغِ تأییدشده توسط ادمین (اختیاری). فقط برای «تأیید واریز» معنا دارد:
            // اگر ارسال شود، همین مبلغ به کیف پول واریز و روی تراکنش ثبت می‌شود (به‌جای
            // مبلغِ خوداظهارِ کاربر). نبودِ آن = رفتار قبلی (سازگاریِ عقب‌رو).
            'verified_amount' => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => __('admin.wallet.status_required'),
            'status.in' => __('admin.wallet.status_invalid'),
            'verified_amount.numeric' => __('admin.wallet.status_invalid'),
        ];
    }
}
