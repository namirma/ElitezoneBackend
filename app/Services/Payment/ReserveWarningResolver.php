<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Cart;
use App\Models\SupplierProfile;
use App\Services\Sales\SalesSettings;
use Carbon\CarbonInterface;

/**
 * هشدارِ «رزرو دیرهنگام» — الحاقیه‌ی ۱، تغییر ۲.
 *
 * اگر فاصله تا `reserve_time` تأمین‌کننده از آستانه کمتر باشد، خریدار باید
 * **پیش از تأیید** بداند دو چیز را:
 *   ۱. وقتِ کمی برای تکمیل پرداخت دارد
 *   ۲. تمدید فقط با پذیرشِ تأمین‌کننده ممکن است — نه خودکار، نه با پرداخت
 *
 * ⚠️ این آستانه **هیچ اثری روی خودِ مهلت ندارد**. مهلت را
 * `reserve_auto_extend_minutes` تأمین‌کننده تعیین می‌کند (پیش‌فرض ۰ = بدون
 * حاشیه). دو متغیر عمداً مستقل‌اند: یکی می‌گوید کِی حرف بزن، دیگری می‌گوید
 * چقدر وقت بده.
 *
 * پوششِ تست: tests/Feature/Payment/PaymentBranchResolverTest.php
 */
final class ReserveWarningResolver
{
    public function __construct(
        private readonly SalesSettings $settings,
    ) {}

    /**
     * @return array{show: bool, remaining_seconds: int, message: string}
     */
    public function forCart(Cart $cart): array
    {
        $reserveTime = $this->supplierReserveTime($cart);

        if ($reserveTime === null || $reserveTime->isPast()) {
            // قیمت‌ها منقضی‌اند — این دیگر «کم‌وقتی» نیست، اصلاً قابل رزرو
            // نیست. مسیرِ خودش (بند ۳.۲) جلویش را می‌گیرد و اینجا هشداری
            // نمایش داده نمی‌شود تا دو پیامِ متناقض به کاربر نرود.
            return $this->none();
        }

        $threshold = $this->settings->reserveWarningThreshold();

        // مقایسه‌ی دو لحظه و نه `diffInMinutes` — کاربن ۲ و ۳ علامت را متفاوت
        // برمی‌گردانند (همان تله‌ای که در CartParkService خورده بودیم).
        if (! $reserveTime->lessThan(now()->add($threshold))) {
            return $this->none();
        }

        $remaining = max(0, now()->diffInSeconds($reserveTime, false));

        return [
            'show' => true,
            'remaining_seconds' => (int) $remaining,
            'message' => __('orders.reserve_warning.short_window', [
                'minutes' => (int) ceil($remaining / 60),
            ]),
        ];
    }

    /** @return array{show: bool, remaining_seconds: int, message: string} */
    private function none(): array
    {
        return ['show' => false, 'remaining_seconds' => 0, 'message' => ''];
    }

    private function supplierReserveTime(Cart $cart): ?CarbonInterface
    {
        $supplierId = $cart->{Cart::SUPPLIER_USER_ID};

        if ($supplierId === null) {
            return null;
        }

        return SupplierProfile::where(SupplierProfile::USER_ID, $supplierId)
            ->value(SupplierProfile::RESERVE_TIME);
    }
}
