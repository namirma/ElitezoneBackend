<?php

namespace App\Http\Requests\Financial;

use App\Models\UserBankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class WalletWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $availableBalance = Auth::user()->wallet->balance ?? 0;

        return [
            'amount' => [
                'required',
                'integer',
                'min:'.(int) config('financial.withdrawal_min'),
                'max:'.$availableBalance,
            ],
            'user_bank_account_id' => [
                'required',
                'integer',
                Rule::exists('user_bank_accounts', 'id')->where(function ($query) {
                    $query->where(UserBankAccount::USER_ID, Auth::id())
                        ->where(UserBankAccount::STATUS, UserBankAccount::STATUS_APPROVED);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => __('auth.withdrawal_amount_min', ['min' => number_format((int) config('financial.withdrawal_min'))]),
            'amount.max' => __('auth.withdrawal_amount_max'),
            'user_bank_account_id.exists' => __('auth.bank_account_invalid'),
        ];
    }
}
