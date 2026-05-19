<?php

namespace App\Http\Requests;

use App\Models\UserBankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreUserBankAccountRequest extends FormRequest
{
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
            $shaba = (string) $this->input(UserBankAccount::SHABA);

            // فقط اگر شبا پر شده بود الگوریتم را چک کن
            if (! empty($shaba) && ! $this->isValidIranIban($shaba)) {
                $v->errors()->add(UserBankAccount::SHABA, __('auth.shaba_invalid_algorithm'));
            }
        });
    }

    private function isValidIranIban(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        if (! preg_match('/^IR\d{24}$/', $iban)) {
            return false;
        }
        $re = substr($iban, 4).substr($iban, 0, 4);
        $num = '';
        foreach (str_split($re) as $ch) {
            $num .= ctype_alpha($ch) ? (ord($ch) - 55) : $ch;
        }
        $rem = 0;
        foreach (str_split($num, 9) as $chunk) {
            $rem = (int) (($rem.$chunk) % 97);
        }

        return $rem === 1;
    }

    public function messages(): array
    {
        return [
            UserBankAccount::SHABA.'.regex' => __('auth.shaba_regex'),
            UserBankAccount::SHABA.'.unique' => __('auth.shaba_unique'),
            UserBankAccount::SHABA.'.required_without' => 'وارد کردن شماره شبا یا شماره کارت الزامی است.',

            UserBankAccount::CARD_NUMBER.'.digits' => 'شماره کارت باید ۱۶ رقم باشد.',
            UserBankAccount::CARD_NUMBER.'.unique' => 'این شماره کارت قبلاً ثبت شده است.',
            UserBankAccount::CARD_NUMBER.'.required_without' => 'وارد کردن شماره کارت یا شماره شبا الزامی است.',
        ];
    }
}
