<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\SupplierProfile;
use Carbon\CarbonInterface;
use Tests\Feature\Cart\CartPriceValidityTest;

/**
 * مهلتِ اعتبارِ قیمت/موجودیِ سبد — «تا چه زمانی این قیمت‌ها معتبرند؟»
 *
 * پاسخِ سبد فیلدِ `price_valid_until` را از همین‌جا می‌گیرد و **null یعنی هیچ
 * مهلتی اعلام نشده** (نه «منقضی»). فرانت باید null را به‌صورت «بدون مهلت» نمایش
 * دهد، نه صفر یا تاریخِ گذشته.
 *
 * چرا یک سرویسِ جدا و نه یک خط داخل CartResponseBuilder: قرار بود این مهلت در
 * آینده از سمتِ تأمین‌کننده بیاید و این کلاس تنها نقطهٔ اتصال باشد — و دقیقاً
 * همان شد. حالا مقدار از `supplier_profiles.reserve_time` می‌آید و هیچ‌کدام از
 * کنترلر/Resource/قراردادِ پاسخ عوض نشدند.
 *
 * سبدی که هنوز تأمین‌کننده‌اش قفل نشده به TTLِ قدیمیِ `config/cart.php` برمی‌گردد
 * که پیش‌فرضش خالی است — یعنی «مهلتی اعلام نشده» و نه عددی ساختگی.
 *
 * @see CartPriceValidityTest
 */
class CartPriceValidityResolver
{
    /**
     * مهلتِ اعتبارِ قیمتِ این سبد، یا null اگر مهلتی اعلام نشده باشد.
     *
     * هر سبد فقط یک تأمین‌کننده دارد (بند ۳.۱)، پس «سختگیرانه‌ترین مهلت میانِ
     * ردیف‌ها» دیگر موضوعیت ندارد — یک تأمین‌کننده، یک `reserve_time`.
     */
    public function resolve(Cart $cart): ?CarbonInterface
    {
        // ✅ همان API تأمین‌کننده‌ای که docblock بالا وعده‌اش را داده بود، حالا
        // هست: `supplier_profiles.reserve_time` (بند ۲.۲ / ۳.۲). یک datetime
        // مطلق است — «قیمت‌های من تا این لحظه معتبرند» — پس مستقیم همان
        // برگردانده می‌شود و هیچ TTLای از روی `updated_at` ساخته نمی‌شود.
        $supplierId = $cart->{Cart::SUPPLIER_USER_ID};

        if ($supplierId !== null) {
            return SupplierProfile::where(SupplierProfile::USER_ID, $supplierId)
                ->value(SupplierProfile::RESERVE_TIME);
        }

        // سبدِ بدونِ تأمین‌کننده‌ی قفل‌شده (خالی، یا مهمان پیش از اولین افزودن)
        // به TTLِ قدیمیِ config برمی‌گردد — که پیش‌فرضش خالی است، یعنی «مهلتی
        // اعلام نشده». این مسیر فقط برای سازگاری است و با قفل‌شدنِ تأمین‌کننده
        // هرگز دوباره خوانده نمی‌شود.
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
