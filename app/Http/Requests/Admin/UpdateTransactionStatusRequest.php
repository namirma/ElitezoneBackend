<?php

namespace App\Http\Requests\Admin;

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
            \App\Models\WalletTransaction::STATUS_CONFIRMED,
            \App\Models\WalletTransaction::STATUS_REJECTED,
        ];
        return [
            'status' => 'required|in:' . implode(',', $allowedStatuses),
            'note' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => __('admin.wallet.status_required'),
            'status.in' => __('admin.wallet.status_invalid'),
        ];
    }
}
