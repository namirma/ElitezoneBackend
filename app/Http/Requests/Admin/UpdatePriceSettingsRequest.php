<?php

namespace App\Http\Requests\Admin;

use App\Models\PriceSetting;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT admin/price-settings — قواعد از `PriceSetting::catalog()` ساخته می‌شوند تا
 * افزودنِ یک پارامتر فقط یک ردیفِ کاتالوگ بخواهد، نه یک قاعده‌ی دستیِ دیگر.
 */
class UpdatePriceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // admin.permission:manage-financial روی روت
    }

    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array', 'min:1']];

        foreach (PriceSetting::catalog() as $code => $meta) {
            $rules["settings.{$code}"] = $meta['unit'] === 'boolean'
                ? ['sometimes', 'boolean']
                : ['sometimes', 'numeric', "min:{$meta['min']}", "max:{$meta['max']}"];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $settings = (array) $this->input('settings', []);
            $catalog = PriceSetting::catalog();

            foreach (array_diff_key($settings, $catalog) as $code => $_) {
                $v->errors()->add("settings.{$code}", "پارامتر «{$code}» شناخته‌شده نیست.");
            }

            // OW + CO هر نشان ≤ ۱۰۰، در برابرِ مقدارِ مؤثر (فرستاده‌شده یا ذخیره‌شده).
            $effective = fn (string $code) => (float) ($settings[$code] ?? PriceSetting::getValue($code, $catalog[$code]['default']));
            $ow = $effective(PriceSetting::CODE_OW_SHARE);
            foreach ([PriceSetting::CODE_CO_SHARE_GOLD, PriceSetting::CODE_CO_SHARE_SILVER, PriceSetting::CODE_CO_SHARE_BRONZE, PriceSetting::CODE_CO_SHARE_DEFAULT] as $coCode) {
                if (! isset($settings[$coCode]) && ! isset($settings[PriceSetting::CODE_OW_SHARE])) {
                    continue;
                }
                if (Money::cmp(Money::add($ow, $effective($coCode)), '100') > 0) {
                    $v->errors()->add("settings.{$coCode}", 'جمع تخفیف خریدار و سهم الیت یار نمی‌تواند از ۱۰۰ درصد بیشتر باشد.');
                }
            }
        });
    }

    public function attributes(): array
    {
        return collect(PriceSetting::catalog())->mapWithKeys(fn ($m, $c) => ["settings.{$c}" => $m['label']])->all();
    }
}
