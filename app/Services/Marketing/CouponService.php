<?php

namespace App\Services\Marketing;

use App\Models\Coupon;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class CouponService
{
    /**
     * ساخت کوپن اختصاصی (درصدی) برای یک کاربر
     *
     * * @param User $user کاربر هدف
     * @param  int  $percent  درصد تخفیف (مثلا 5)
     * @param  int  $expireDays  اعتبار به روز (مثلا 2)
     * @param  string  $reason  علت صدور
     */
    public function createPersonalCoupon(User $user, int $percent, int $expireDays, string $reason): Coupon
    {
        // تولید کد: ۳ حرف اول اسم + خط تیره + ۴ حرف رندوم
        // مثال: ALIREZA -> ALI-X9Y2
        $namePart = Str::upper(Str::substr($user->name ?? 'USR', 0, 3));
        $randomPart = Str::upper(Str::random(4));
        $finalCode = "{$namePart}-{$randomPart}";

        return Coupon::create([
            Coupon::CODE => $finalCode,
            Coupon::USER_ID => $user->id,
            Coupon::TYPE => Coupon::TYPE_PERCENT,
            Coupon::AMOUNT => $percent,
            Coupon::EXPIRES_AT => Carbon::now()->addDays($expireDays),
            Coupon::REASON => $reason,
        ]);
    }
}
