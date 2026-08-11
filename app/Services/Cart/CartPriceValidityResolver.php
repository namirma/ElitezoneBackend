<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Cart;
use Carbon\CarbonInterface;
use Tests\Feature\Cart\CartPriceValidityTest;

/**
 * مهلتِ اعتبارِ قیمت/موجودیِ سبد — «تا چه زمانی این قیمت‌ها معتبرند؟»
 *
 * پاسخِ سبد فیلدِ `price_valid_until` را از همین‌جا می‌گیرد و **null یعنی هیچ
 * مهلتی اعلام نشده** (نه «منقضی»). فرانت باید null را به‌صورت «بدون مهلت» نمایش
 * دهد، نه صفر یا تاریخِ گذشته.
 *
 * چرا یک سرویسِ جدا و نه یک خط داخل CartResponseBuilder: قرار است این مهلت در
 * آینده از **API تأمین‌کننده** بیاید (هر تأمین‌کننده اعتبارِ قیمتِ خودش را اعلام
 * می‌کند). این کلاس تنها نقطهٔ اتصال است، پس وقتی آن API آمد فقط `resolve()`
 * عوض می‌شود و نه کنترلر، نه Resource، نه قراردادِ پاسخ.
 *
 * تا آن روز مقدار از یک TTLِ ساده در `config/cart.php` می‌آید که پیش‌فرضش خالی
 * است — یعنی رفتارِ امروز دقیقاً «مهلتی اعلام نشده» است و هیچ عددی از هوا ساخته
 * نمی‌شود. اگر کسب‌وکار خواست یک مهلتِ ثابت اعلام کند، یک متغیرِ env کافی است.
 *
 * @see CartPriceValidityTest
 */
class CartPriceValidityResolver
{
    /**
     * مهلتِ اعتبارِ قیمتِ این سبد، یا null اگر مهلتی اعلام نشده باشد.
     *
     * ⚠️ نقطهٔ اتصالِ آیندهٔ API تأمین‌کننده: وقتی آن API آمد، اینجا کمترین
     * مهلتِ اعلام‌شدهٔ میانِ ردیف‌های سبد برگردانده می‌شود (سختگیرانه‌ترین مهلت
     * تعیین‌کننده است، چون سفارش یکجا ثبت می‌شود).
     */
    public function resolve(Cart $cart): ?CarbonInterface
    {
        $ttlMinutes = config('cart.price_validity_ttl_minutes');

        if ($ttlMinutes === null || (int) $ttlMinutes <= 0) {
            return null;
        }

        // مبنا `updated_at`ِ سبد است نه now(): وگرنه هر بار که کاربر صفحه را
        // refresh می‌کند مهلت جلو می‌رود و عملاً هرگز منقضی نمی‌شود.
        $base = $cart->updated_at ?? now();

        return $base->copy()->addMinutes((int) $ttlMinutes);
    }

    /** همان مقدار، در قالبِ رشتهٔ ISO-8601 که پاسخِ API برمی‌گرداند. */
    public function resolveAsIso(Cart $cart): ?string
    {
        return $this->resolve($cart)?->toIso8601String();
    }
}
