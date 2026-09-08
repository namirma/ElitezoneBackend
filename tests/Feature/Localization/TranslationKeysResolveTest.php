<?php

namespace Tests\Feature\Localization;

use App\Http\Requests\Address\StoreAddressRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `success()` / `error()` کلید را داخلاً از `__()` رد می‌کنند، و `__()` کلیدِ
 * پیدانشده را *عیناً* برمی‌گرداند. پس یک کلیدِ گم‌شده یعنی بدنهٔ پاسخ واقعاً
 * رشتهٔ خامِ «global.forbidden» است، نه یک متن فارسی.
 *
 * سیزده کلید این‌طور بودند — از جمله هر ۴۰۳ دانلود سند، کلِ پیام‌های فرم آدرس،
 * و سقف/کف شارژ کیف پول.
 */
class TranslationKeysResolveTest extends TestCase
{
    /** کلیدهایی که در کد استفاده می‌شوند و باید حتماً resolve شوند. */
    public static function keyProvider(): array
    {
        return array_map(fn ($k) => [$k], [
            'global.forbidden',
            'global.unauthorized',
            'global.information_received_successfully',
            'checkout.cart_is_empty',
            'profile.mobile_updated_successfully',
            'auth.code_invalid',
            'auth.mobile_format_invalid',
            'auth.deposit_amount_min',
            'auth.deposit_amount_max_shaparak',
            'auth.deposit_amount_max_podium',
            'auth.policy.finalize_invoice_denied',
            'address.postal_address_required',
            'address.receiver_name_required',
            'address.receiver_mobile_required',
            'address.receiver_mobile_regex',
            'address.postal_code_regex',
            'address.province_id_exists',
            'address.city_id_exists',
        ]);
    }

    #[DataProvider('keyProvider')]
    #[Test]
    public function the_key_resolves_to_a_real_message(string $key): void
    {
        $this->assertNotSame(
            $key,
            __($key),
            "کلید ترجمهٔ «{$key}» وجود ندارد و خام به کاربر برمی‌گردد."
        );
    }

    #[Test]
    public function default_validation_messages_are_persian(): void
    {
        // lang/fa/validation.php وجود نداشت، پس هر قاعده‌ای که messages() اختصاصی
        // نداشت (بیش از ۹۰ فراخوانیِ inline validate در کنترلرها) به انگلیسیِ
        // lang/en برمی‌گشت — روی محصولی که locale=fa است.
        foreach (['required', 'integer', 'exists', 'mimes', 'unique', 'numeric'] as $rule) {
            $message = __('validation.'.$rule);

            $this->assertIsString($message);
            $this->assertStringNotContainsString(
                'The :attribute',
                $message,
                "پیام پیش‌فرض «validation.{$rule}» هنوز انگلیسی است."
            );
        }
    }

    #[Test]
    public function the_address_request_messages_all_resolve(): void
    {
        $request = new StoreAddressRequest;

        foreach ($request->messages() as $field => $message) {
            $this->assertStringNotContainsString(
                'address.',
                $message,
                "پیام فیلد «{$field}» کلید خام برمی‌گرداند: {$message}"
            );
        }
    }
}
