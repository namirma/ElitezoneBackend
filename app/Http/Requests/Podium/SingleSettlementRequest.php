<?php

namespace App\Http\Requests\Podium;

use App\Support\IranIban;
use Illuminate\Foundation\Http\FormRequest;

class SingleSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $toEn = static function ($v) {
            $map = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

            return strtr((string) $v, $map);
        };

        $amount = $toEn($this->input('amount'));
        $toolCode = strtoupper(trim((string) $this->input('toolCode')));
        $wallet = $this->filled('wallet') ? strtoupper(trim((string) $this->input('wallet'))) : null;
        $guild = $this->filled('guildCode') ? trim((string) $this->input('guildCode')) : null;

        $ibanRaw = strtoupper(preg_replace('/\s+/', '', (string) $this->input('toolId', '')));
        $pid = $this->filled('paymentId') ? preg_replace('/[^A-Za-z0-9\-]/', '', (string) $this->input('paymentId')) : null;
        $uid = $this->filled('uniqueId') ? preg_replace('/[^A-Za-z0-9\-]/', '', (string) $this->input('uniqueId')) : null;

        $this->merge([
            'amount' => is_numeric($amount) ? (int) $amount : $amount,
            'toolCode' => $toolCode,
            'wallet' => $wallet,
            'guildCode' => $guild,
            'toolId' => $ibanRaw,
            'paymentId' => $pid,
            'uniqueId' => $uid,
            'currencyCode' => $this->input('currencyCode', 'IRR'),
        ]);
        $raw = (string) $this->input('toolId', '');
        $iban = strtoupper(preg_replace('/\s+/', '', $raw));

        if (preg_match('/^\d{24}$/', $iban)) {
            $iban = 'IR'.$iban;
        }

        $this->merge([
            'toolId' => $iban,
        ]);
    }

    public function rules(): array
    {
        return [
            'amount' => ['bail', 'required', 'integer', 'min:1000', 'max:'.config('podium.limits.max_amount', 2000000000)],
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['nullable', 'string', 'max:100'],
            'toolCode' => ['required', 'in:SETTLEMENT_TOOL_PAYA,SETTLEMENT_TOOL_SATNA'],
            'toolId' => ['required', 'string', 'regex:/^IR\d{24}$/'],
            'wallet' => ['nullable', 'in:PODLAND_WALLET', 'required_without:guildCode'],
            'guildCode' => ['nullable', 'string', 'max:100', 'required_without:wallet'],
            'currencyCode' => ['nullable', 'in:IRR'],
            'description' => ['nullable', 'string', 'max:500'],
            'paymentId' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9\-]+$/'],
            'uniqueId' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9\-]+$/'],
            'detailType' => ['nullable', 'string', 'in:DETAIL_TYPE_DEPOSIT_SALARY,DETAIL_TYPE_INSURANCE_SERVICE_AFFAIR,DETAIL_TYPE_HEALTH_AFFAIR,DETAIL_TYPE_STOCK_INVESTMENT_AFFAIR,DETAIL_TYPE_CURRENCY_AFFAIRS,DETAIL_TYPE_BILL_DEBT_PAYMENT,DETAIL_TYPE_PENSION_AFFAIRS,DETAIL_TYPE_MOVABLE_PROPERTY_TRANSACTIONS,DETAIL_TYPE_REAL_ESTATE_TRANSACTIONS,DETAIL_TYPE_MANAGE_LIQUIDITY,DETAIL_TYPE_CUSTOMS_DUTIES,DETAIL_TYPE_TAX_SETTLEMENT,DETAIL_TYPE_OTHER_GOVERNMENT_SERVICES,DETAIL_TYPE_LOAN_REPAYMENT,DETAIL_TYPE_DEPOSIT_OF_ASSURANCE,DETAIL_TYPE_GENERAL_COST,DETAIL_TYPE_CHARITABLE_DONATIONS,DETAIL_TYPE_BUY_COMMODIT,DETAIL_TYPE_BUY_SERVICE'],
            'sourceDescription' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $raw = preg_replace('/\s+/', '', (string) $this->input('toolId', ''));

            if (preg_match('/^\d{16}$/', $raw)) {
                $v->errors()->add('toolId', 'به نظر شماره کارت است؛ برای PAYA/SATNA باید شِبا (IR + 24 رقم) ارسال شود.');
            } elseif (preg_match('/^\d+$/', $raw) && strlen($raw) !== 24) {
                $v->errors()->add('toolId', 'طول مقدار ارسالی با شِبا سازگار نیست. شِبا باید 24 رقم (یا IR + 24 رقم) باشد.');
            }

            // چک‌سام فعلی‌ات عالیه؛ نگه‌دار
            $toolCode = (string) $this->input('toolCode');
            $iban = (string) $this->input('toolId');
            if (in_array($toolCode, ['SETTLEMENT_TOOL_PAYA', 'SETTLEMENT_TOOL_SATNA'], true)) {
                if (! IranIban::isValid($iban)) {
                    $v->errors()->add('toolId', 'شماره شبا نامعتبر است (IBAN checksum).');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'wallet.required_without' => 'ارسال یکی از wallet یا guildCode الزامی است.',
            'guildCode.required_without' => 'ارسال یکی از wallet یا guildCode الزامی است.',
            'paymentId.regex' => 'paymentId فقط می‌تواند شامل حروف، اعداد و خط‌تیره باشد.',
            'uniqueId.regex' => 'uniqueId فقط می‌تواند شامل حروف، اعداد و خط‌تیره باشد.',
            'toolId.regex' => 'فرمت شبا باید IR و 24 رقم باشد.',
        ];
    }
}
