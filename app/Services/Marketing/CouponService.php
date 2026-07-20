<?php

namespace App\Services\Marketing;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class CouponService
{
    /**
     * اعتبارسنجی کد تخفیف برای استفاده در checkout.
     *
     * @throws BusinessException با پیام مناسب کاربر (422 از Handler)
     */
    public function validateForCheckout(User $user, string $code, bool $lock = false): Coupon
    {
        $query = Coupon::where(Coupon::CODE, $code);

        if ($lock) {
            // داخل DB::transaction ثبت سفارش — جلوگیری از مصرف همزمان یک کوپن
            $query->lockForUpdate();
        }

        $coupon = $query->first();

        if (! $coupon) {
            throw new BusinessException(__('coupons.not_found'));
        }

        if (! $coupon->{Coupon::IS_ACTIVE}) {
            throw new BusinessException(__('coupons.inactive'));
        }

        if ($coupon->{Coupon::EXPIRES_AT} && $coupon->{Coupon::EXPIRES_AT}->isPast()) {
            throw new BusinessException(__('coupons.expired'));
        }

        if (! is_null($coupon->{Coupon::USED_AT})) {
            throw new BusinessException(__('coupons.already_used'));
        }

        if ($coupon->{Coupon::USER_ID} && (int) $coupon->{Coupon::USER_ID} !== (int) $user->id) {
            throw new BusinessException(__('coupons.not_yours'));
        }

        return $coupon;
    }

    /**
     * مبلغ تخفیف (ریال) برای یک مبلغ قابل پرداخت — هرگز بیشتر از خود مبلغ نمی‌شود.
     */
    public function calculateDiscount(Coupon $coupon, float $payable): float
    {
        $discount = $coupon->{Coupon::TYPE} === Coupon::TYPE_PERCENT
            ? $payable * ((float) $coupon->{Coupon::AMOUNT} / 100)
            : (float) $coupon->{Coupon::AMOUNT};

        return round(min(max($discount, 0), $payable), 2);
    }

    /**
     * مصرف کوپن (used_at). باید داخل همان DB::transaction ثبت سفارش صدا زده شود
     * و کوپن با lock=true اعتبارسنجی شده باشد.
     */
    public function redeem(Coupon $coupon): void
    {
        $coupon->{Coupon::USED_AT} = now();
        $coupon->save();
    }

    /**
     * آزادسازی کوپنِ سفارشِ لغوشدهٔ PENDING (پولی جابه‌جا نشده).
     * برای سفارش‌های پرداخت‌شده، کوپن مصرف‌شده باقی می‌ماند و leg تخفیف
     * در RefundOrderToWalletAction برگشت می‌خورد.
     */
    public function releaseForOrder(Order $order): void
    {
        if (! $order->coupon_id) {
            return;
        }

        Coupon::where(Coupon::ID, $order->coupon_id)
            ->whereNotNull(Coupon::USED_AT)
            ->update([Coupon::USED_AT => null]);
    }

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
