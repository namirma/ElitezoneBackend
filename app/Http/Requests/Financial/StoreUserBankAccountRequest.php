<?php

namespace App\Http\Requests\Financial;

use App\Http\Requests\Concerns\ReadsScalarInput;
use App\Models\UserBankAccount;
use App\Support\IranIban;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreUserBankAccountRequest extends FormRequest
{
    use ReadsScalarInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            UserBankAccount::SHABA => [
                'required_without:'.UserBankAccount::CARD_NUMBER,
                'nullable',
                'string',
                'regex:/^IR\d{24}$/',
                Rule::unique('user_bank_accounts', 'shaba')->where('user_id', Auth::id()),
            ],

            UserBankAccount::CARD_NUMBER => [
                'required_without:'.UserBankAccount::SHABA,
                'nullable',
                'string',
                'digits:16',
                Rule::unique('user_bank_accounts', 'card_number')->where('user_id', Auth::id()),
            ],

            UserBankAccount::IS_DEFAULT => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $shaba = $this->scalarInput(UserBankAccount::SHABA);

            // فقط اگر شبا پر شده بود الگوریتم را چک کن
            if (! empty($shaba) && ! IranIban::isValid($shaba)) {
                $v->errors()->add(UserBankAccount::SHABA, __('auth.shaba_invalid_algorithm'));
            }
        });
    }

    public function messages(): array
    {
        return [
            UserBankAccount::SHABA.'.regex' => __('auth.shaba_regex'),
            UserBankAccount::SHABA.'.unique' => __('auth.shaba_unique'),
            UserBankAccount::SHABA.'.required_without' => __('bank.validation.shaba_or_card_required'),

            UserBankAccount::CARD_NUMBER.'.digits' => __('bank.validation.card_digits'),
            UserBankAccount::CARD_NUMBER.'.unique' => __('bank.validation.card_unique'),
            UserBankAccount::CARD_NUMBER.'.required_without' => __('bank.validation.card_or_shaba_required'),
        ];
    }
}
