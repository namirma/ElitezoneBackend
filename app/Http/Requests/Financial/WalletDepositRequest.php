<?php

namespace App\Http\Requests\Financial;

use App\Services\Sales\SalesSettings;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WalletDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'amount' => [
                'required',
                'integer',
                'min:'.(int) config('financial.deposit_min', 10_000),
            ],
            'payment_method' => [
                'required',
                'string',
                Rule::in(['podium', 'shaparak', 'bank_transfer']),
            ],
        ];
        $method = $this->input('payment_method');

        if ($method === 'shaparak') {
            $rules['amount'][] = 'max:'.self::shaparakCap();
        } elseif ($method === 'podium') {
            $rules['amount'][] = 'max:'.(int) config('financial.deposit_max_podium', 2_000_000_000);
        }

        return $rules;
    }

    public function messages(): array
    {
        // سقف باید با همان مقداری که rules() اعمال می‌کند ساخته شود و به‌صورت
        // پارامتر :max به ترجمه پاس داده شود؛ بدون آن، پیام با placeholderِ خام
        // به کاربر می‌رسید.
        $method = $this->input('payment_method');
        $maxMessage = '';

        if ($method === 'shaparak') {
            $maxMessage = __('auth.deposit_amount_max_shaparak', [
                'max' => Money::format(self::shaparakCap()),
            ]);
        } elseif ($method === 'podium') {
            $maxMessage = __('auth.deposit_amount_max_podium', [
                'max' => Money::format((int) config('financial.deposit_max_podium', 2_000_000_000)),
            ]);
        }

        return [
            'amount.min' => __('auth.deposit_amount_min', ['min' => Money::format((int) config('financial.deposit_min', 10_000))]),
            'amount.max' => $maxMessage,
            'payment_method.in' => __('auth.payment_method_invalid'),
        ];
    }

    /**
     * سقفِ یک شارژ با درگاه کارت = سقفِ تراکنشِ درگاه در تنظیماتِ فروش (`shaparak_value`، علی
     * ۱۴۰۵/۰۷/۱۹: ۲ میلیارد ریال). یک منبع برای این درخواست و `GET /settings/public`؛
     * `financial.deposit_max_shaparak` دیگر خوانده نمی‌شود.
     */
    public static function shaparakCap(): int
    {
        return (int) app(SalesSettings::class)->shaparakValue();
    }
}
