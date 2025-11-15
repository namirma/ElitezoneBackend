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
            UserBankAccount::BANK_NAME => ['required', 'string', 'max:100'],
            UserBankAccount::ACCOUNT_HOLDER_NAME => ['required', 'string', 'max:100'],
            UserBankAccount::SHABA => [
                'required',
                'string',
                'regex:/^IR\d{24}$/',
                Rule::unique('user_bank_accounts')->where('user_id', Auth::id())
            ],
            UserBankAccount::IS_DEFAULT => ['nullable', 'boolean'],
        ];
    }
    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $shaba = (string) $this->input(UserBankAccount::SHABA);
            if ($shaba && !$this->isValidIranIban($shaba)) {
                $v->errors()->add(UserBankAccount::SHABA, __('auth.shaba_invalid_algorithm'));
            }
        });
    }
    private function isValidIranIban(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        if (!preg_match('/^IR\d{24}$/', $iban)) return false;
        $re = substr($iban, 4) . substr($iban, 0, 4);
        $num = '';
        foreach (str_split($re) as $ch) $num .= ctype_alpha($ch) ? (ord($ch) - 55) : $ch;
        $rem = 0;
        foreach (str_split($num, 9) as $chunk) $rem = (int)(($rem . $chunk) % 97);
        return $rem === 1;
    }
    public function messages(): array
    {
        return [
            'shaba.regex' => __('auth.shaba_regex'),
            'shaba.unique' => __('auth.shaba_unique'),
        ];
    }
}
