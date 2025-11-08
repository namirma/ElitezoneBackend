<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class WalletWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // دریافت موجودی قابل برداشت کاربر
        $availableBalance = Auth::user()->wallet->balance ?? 0;

        return [
            // مبلغ باید عددی، مثبت و کمتر یا مساوی موجودی قابل برداشت باشد
            'amount' => ['required', 'integer', 'min:50000', 'max:' . $availableBalance],

            // کاربر باید شبای خود را برای واریز وارد کند
            // (از منطق اعتبارسنجی شبا در PodiumSettlementRequest استفاده می‌کنیم)
            'shaba'  => ['required', 'string', 'regex:/^IR\d{24}$/'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            if (!$this->isValidIranIban((string)$this->input('shaba'))) {
                $v->errors()->add('shaba', 'شماره شبا نامعتبر است (IBAN checksum).');
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
}
