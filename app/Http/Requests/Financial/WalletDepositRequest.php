<?php

namespace App\Http\Requests\Financial;

use App\Services\Sales\SalesSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WalletDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * سقفِ یک واریز، بر حسبِ ریال — یا `null` وقتی روش سقفی ندارد.
     *
     * سقفِ درگاه از `SalesSettings::shaparakValue()` می‌آید و نه از یک ثابتِ
     * کانفیگ، چون **همان مقدار از قبل تصمیم می‌گیرد سفارش به درگاه برود یا به
     * رزرو** (`PaymentBranchResolver`). تا پیش از این دو تعریفِ جدا از یک
     * واقعیت وجود داشت و ۱۰ برابر اختلاف داشتند: تصمیم‌گیر ۲٬۰۰۰٬۰۰۰٬۰۰۰ ریال
     * را ملاک می‌گرفت و همین اعتبارسنجی ۲۰۰٬۰۰۰٬۰۰۰. نتیجه‌اش روی یک سفارشِ
     * واقعی دیده شد: سایت «شارژ ۳۸۲٬۸۰۰٬۰۰۰ ریال از درگاه» را پیشنهاد می‌داد و
     * همین کلاس آن را ۴۲۲ می‌کرد — هر کسریِ بینِ ۲۰ تا ۲۰۰ میلیون تومان روی آن
     * ریل **پرداخت‌ناپذیر** بود.
     *
     * علی سقفِ واقعیِ بانک را ۲۰۰ میلیون تومان تأیید کرد، یعنی این سمت اشتباه
     * بود و پرداخت‌های مشروع را رد می‌کرد. حالا یک عدد است و ادمین می‌تواند
     * وقتی بانک سقفش را عوض کرد، از تنظیماتِ فروش تغییرش دهد — بدونِ استقرار.
     */
    private function maxAmountFor(?string $method): ?int
    {
        return match ($method) {
            'shaparak' => (int) app(SalesSettings::class)->shaparakValue(),
            'podium' => (int) config('financial.deposit_max_podium', 2_000_000_000),
            default => null,
        };
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
        $max = $this->maxAmountFor($this->input('payment_method'));

        if ($max !== null) {
            $rules['amount'][] = 'max:'.$max;
        }

        return $rules;
    }

    public function messages(): array
    {
        // پیام از **همان** متدی می‌آید که قاعده را می‌سازد. وقتی هرکدام سقف را
        // جدا می‌خواندند، یک تغییر در یکی، پیامی می‌ساخت که عددِ اعمال‌شده نبود.
        $method = $this->input('payment_method');
        $max = $this->maxAmountFor($method);
        $maxMessage = '';

        if ($max !== null) {
            $maxMessage = __(
                $method === 'shaparak' ? 'auth.deposit_amount_max_shaparak' : 'auth.deposit_amount_max_podium',
                ['max' => number_format($max)]
            );
        }

        return [
            'amount.min' => __('auth.deposit_amount_min', ['min' => number_format((int) config('financial.deposit_min', 10_000))]),
            'amount.max' => $maxMessage,
            'payment_method.in' => __('auth.payment_method_invalid'),
        ];
    }
}
