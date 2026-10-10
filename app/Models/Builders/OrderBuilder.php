<?php

namespace App\Models\Builders;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class OrderBuilder extends Builder
{
    /**
     * فقط سفارش‌های فعال (جاری)
     */
    public function current(): self
    {
        return $this->whereIn(Order::STATUS, [
            Order::STATUS_RESERVED,
            Order::STATUS_PROCESSING,
            Order::STATUS_AWAITING_WEIGHT_CORRECTION,
            Order::STATUS_SHIPPED,
            Order::STATUS_PARTIALLY_DELIVERED,
            Order::STATUS_RETURNED_TO_ORIGIN,
        ]);
    }

    /**
     * فقط سفارش‌های پرداخت شده
     */
    public function paid(): self
    {
        return $this->where(Order::PAYMENT_STATUS, Order::PAYMENT_PAID);
    }

    /**
     * سفارش‌های مربوط به یک کاربر (چه خریدار، چه ذی‌نفع)
     */
    public function forUser(int $userId): self
    {
        return $this->where(function ($q) use ($userId) {
            $q->where(Order::PLACER_USER_ID, $userId)
                ->orWhere(Order::BENEFICIARY_USER_ID, $userId);
        });
    }

    /**
     * «سفارش‌های من» (خرید برای خودم) = هر سفارشی که در سمتِ خریدارش طرف هستم.
     *
     * پیش از این یعنی `placer = me AND (beneficiary IS NULL OR = me)`، که در
     * خرید-برای-دیگری **هیچ‌کدام از دو طرف** را برنمی‌گرداند: الیت‌یار با شرطِ
     * ذی‌نفع بیرون می‌افتاد و کارفرما با شرطِ ثبت‌کننده. پس سفارشی که الیت‌یار
     * برای کارفرما بسته در لیستِ هیچ‌کس نبود، و چون تأییدِ تحویل payer-only است،
     * تنها راهِ باقی‌مانده برای پرداخت‌کننده لینکِ امضاشده‌ی پیامکی بود.
     *
     * همان مجموعه‌ای است که `OrderPolicy::view` سمتِ خریدار مجاز می‌داند. فهرست و
     * شمارنده‌ی تب (`GET /profile/orders/counts`) هر دو از همین می‌خوانند.
     */
    public function buyerSideOf(int $userId): self
    {
        return $this->where(function ($q) use ($userId) {
            $q->where(Order::PLACER_USER_ID, $userId)
                ->orWhere(Order::BENEFICIARY_USER_ID, $userId)
                ->orWhere(Order::PAYER_USER_ID, $userId);
        });
    }

    /** «خرید برای دیگران» = ثبت‌شده توسطِ من برای ذی‌نفعِ دیگر. */
    public function placedForOthersBy(int $userId): self
    {
        return $this->where(Order::PLACER_USER_ID, $userId)
            ->whereNotNull(Order::BENEFICIARY_USER_ID);
    }

    /** گروهِ وضعیتِ فهرست‌های پروفایل؛ گروهِ ناشناخته فیلتری نمی‌زند (رفتارِ قبلیِ فهرست‌ها). */
    public function inStatusGroup(string $group): self
    {
        return match ($group) {
            'current' => $this->current(),
            'delivered' => $this->delivered(),
            'cancelled' => $this->cancelled(),
            default => $this,
        };
    }

    /**
     * سفارش‌های منقضی شده (جهت جاب‌های سیستمی)
     */
    public function expired(): self
    {
        return $this->where(Order::STATUS, Order::STATUS_RESERVED)
            ->where(Order::RESERVATION_EXPIRES_AT, '<', now());
    }

    /**
     * فقط سفارش‌های تحویل‌داده‌شده
     */
    public function delivered(): self
    {
        return $this->where(Order::STATUS, Order::STATUS_DELIVERED);
    }

    /**
     * سفارش‌های لغو یا ناموفق
     */
    public function cancelled(): self
    {
        return $this->where(Order::STATUS, Order::STATUS_CANCELLED);
    }

    /**
     * سفارش‌های مربوط به یک تامین‌کننده خاص
     * (با استفاده از Relationship)
     */
    public function forSupplier(int $supplierId): self
    {
        return $this->whereHas('items', function ($q) use ($supplierId) {
            $q->where('supplier_user_id', $supplierId);
        });
    }

    /**
     * سفارش‌هایی که این تأمین‌کننده هنوز نسبت به آن‌ها تعهدِ باز دارد.
     *
     * ⚠️ **تنها مرجعِ «تسویه شده یا نه» برای تأمین‌کننده.** هر مسیری که نقشِ
     * `supplier` را می‌گیرد باید از همین بگذرد — چون گرفتنِ نقش یعنی
     * `OrderPolicy::manageAsSupplier` دیگر رد می‌کند، و آن‌وقت تأمین‌کننده نه
     * بارنامه می‌تواند ثبت کند، نه فاکتور مودیان (سند O) بدهد، نه خسارت عودت.
     * سفارش قفل می‌شود و **هولد مالیاتش هرگز آزاد نمی‌شود** — بدون خطا، فقط
     * یک ۴۰۳ در مسیری که دیگر کسی صدایش نمی‌زند.
     *
     * سه شرط، و هر سه لازم‌اند:
     *
     * ۱. وضعیت پایانی نیست → بار هنوز در جریان است.
     * ۲. `supplier_debt_pending` → به سازه بدهکار است؛ رفتنش یعنی طلبِ
     *    وصول‌نشده و عودتِ خریدار که در `balance_hold` گیر می‌ماند.
     * ۳. باسکول خورده ولی `supplier_hold_released_at` خالی است → سند O نیامده
     *    و مالیاتش هنوز هولد است. **این شرط از دو تای دیگر مستقل است:** سفارش
     *    می‌تواند `delivered` باشد و پولش همچنان معلق بماند، که دقیقاً همان
     *    حالتی بود که گاردِ وضعیت‌محورِ قبلی از آن رد می‌شد.
     */
    public function unsettledForSupplier(int $supplierId): self
    {
        return $this->forSupplier($supplierId)->where(function ($q) {
            $q->whereNotIn(Order::STATUS, [
                Order::STATUS_DELIVERED,
                Order::STATUS_CANCELLED,
            ])
                ->orWhere(Order::SUPPLIER_DEBT_PENDING, true)
                ->orWhere(function ($vat) {
                    $vat->whereNotNull(Order::WEIGHING_COMPLETED_AT)
                        ->whereNull(Order::SUPPLIER_HOLD_RELEASED_AT);
                });
        });
    }
}
