<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Exceptions\BusinessException;
use App\Models\AbandonedCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\SupplierProfile;
use App\Services\Sales\SalesSettings;
use Carbon\CarbonInterface;

/**
 * مهلتِ نگهداری سبد و قفلِ تأمین‌کننده — بند ۳.۱ سند بازطراحی.
 *
 * سبد جانشینِ رکوردِ `Order` با وضعیت `pending` است. آن رکورد موجودی را کسر
 * می‌کرد و تا انقضا نگه می‌داشت؛ سبد هیچ‌کدام را نمی‌کند، پس تنها چیزی که
 * جلوی «سبدِ ابدی» را می‌گیرد همین مهلت است.
 */
final class CartParkService
{
    public function __construct(
        private readonly SalesSettings $settings,
    ) {}

    /**
     * مهلت را فقط اگر هنوز تنظیم نشده می‌گذارد.
     *
     * **ویرایشِ سبد مهلت را ریست نمی‌کند** (بند ۳.۱) و این خودِ قاعده است، نه
     * یک بهینه‌سازی: با ریست‌شدن، هر افزودن/کم‌کردنِ یک قلم مهلت را جلو می‌برد
     * و سبد عملاً هرگز منقضی نمی‌شد. زمان از **افزودن اولین قلم به سبد خالی**
     * شمرده می‌شود.
     *
     * سبدِ مهمان مهلت نمی‌گیرد؛ رفتارش همان `carts:prune-guests` می‌ماند.
     */
    public function parkIfNeeded(Cart $cart): void
    {
        if ($cart->{Cart::USER_ID} === null) {
            return;
        }

        if ($cart->{Cart::PARKED_UNTIL} !== null) {
            return;
        }

        $cart->forceFill([
            Cart::PARKED_UNTIL => $this->deadlineFor($cart),
            Cart::PARK_WARNED_AT => null,
        ])->save();
    }

    /**
     * مهلت، با در نظر گرفتنِ نزدیکیِ `reserve_time` تأمین‌کننده — بند ۳.۱.
     *
     * اگر اعتبارِ قیمت زودتر از خودِ مهلتِ سبد تمام شود، مهلت به **پس از**
     * `reserve_time` منتقل می‌شود، نه قبلش. دلیل: کاربر باید فرصتِ کاملِ
     * `park_time` را روی قیمتِ *جدید* داشته باشد. با فرمولِ ساده‌ی
     * `now + park_time` سبدی ساخته می‌شد که وسطِ عمرش قیمتش عوض می‌شود و
     * کاربر روی عددی تصمیم می‌گیرد که دیگر معتبر نیست.
     */
    private function deadlineFor(Cart $cart): CarbonInterface
    {
        $parkTime = $this->settings->parkTime();
        $reserveTime = $this->supplierReserveTime($cart);

        // مقایسه‌ی مستقیمِ دو لحظه و نه `diffInMinutes`: کاربنِ ۳ (شاخه‌ی L13)
        // مقدارِ **علامت‌دار** برمی‌گرداند و کاربنِ ۲ قدرمطلق، پس همین شرط روی دو
        // شاخه دو جواب می‌داد — روی L13 همیشه شاخه‌ی «نزدیک» را می‌گرفت.
        if ($reserveTime !== null
            && $reserveTime->isFuture()
            && $reserveTime->lessThan(now()->add($parkTime))
        ) {
            return $reserveTime->copy()->add($parkTime);
        }

        return now()->add($parkTime);
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

    /**
     * سبدهایی که منتظرِ اعلامِ قیمتِ جدیدِ این تأمین‌کننده مانده‌اند را دوباره
     * مهلت‌دار می‌کند — بند ۳.۲.
     *
     * «سبدهای منتظر تا لحظه‌ی اعلام قیمت جدید زنده می‌مانند؛ سپس به‌روز و به
     * کاربر اطلاع داده می‌شود.» پس مهلتِ تازه از **حالا** شمرده می‌شود: کاربر
     * تمامِ `park_time` را روی قیمتِ جدید دارد، نه باقی‌مانده‌ی مهلتِ قبلی.
     *
     * @return list<int> شناسه‌ی سبدهایی که به‌روز شدند
     */
    public function reparkWaitingCartsFor(int $supplierUserId): array
    {
        $carts = Cart::query()
            ->whereNotNull(Cart::USER_ID)
            ->where(Cart::SUPPLIER_USER_ID, $supplierUserId)
            ->whereNotNull(Cart::PARKED_UNTIL)
            ->get();

        $touched = [];

        foreach ($carts as $cart) {
            $cart->forceFill([
                Cart::PARKED_UNTIL => now()->add($this->settings->parkTime()),
                Cart::PARK_WARNED_AT => null,
            ])->save();

            $touched[] = (int) $cart->id;
        }

        return $touched;
    }

    /**
     * با خالی‌شدنِ سبد، مهلت هم برداشته می‌شود.
     *
     * وگرنه کاربری که همه‌ی اقلام را حذف کرده و بعداً برگشته، سبدِ تازه‌اش
     * مهلتِ کهنه‌ی قبلی را به ارث می‌برد و می‌تواند بلافاصله منقضی شود.
     */
    public function releaseIfEmpty(Cart $cart): void
    {
        if ($cart->items()->exists()) {
            return;
        }

        $cart->forceFill([
            Cart::PARKED_UNTIL => null,
            Cart::PARK_WARNED_AT => null,
            Cart::SUPPLIER_USER_ID => null,
        ])->save();
    }

    /**
     * قفلِ تک‌تأمین‌کننده — بند ۳.۱.
     *
     * اولین قلم تأمین‌کننده‌ی سبد را تعیین می‌کند؛ از آن پس هر کالای
     * تأمین‌کننده‌ی دیگر ۴۲۲ می‌گیرد. تسویه‌ی مالی یک‌خطی است (POO/PTS برای یک
     * تأمین‌کننده محاسبه می‌شود)، پس این قاعده پیش از این هم وجود داشت — ولی
     * فقط در `PlaceOrderAction::assertSingleSupplier` و در **لحظه‌ی ثبت سفارش**.
     * یعنی کاربر می‌توانست سبدی بسازد که تا آخرین مرحله معتبر به‌نظر برسد و
     * بعد رد شود. حالا در همان لحظه‌ی افزودن گفته می‌شود.
     *
     * @throws BusinessException وقتی کالا از تأمین‌کننده‌ی دیگری باشد
     */
    public function assertSupplierAllowed(Cart $cart, ?int $supplierUserId): void
    {
        if ($supplierUserId === null) {
            return;
        }

        $locked = $cart->{Cart::SUPPLIER_USER_ID};

        if ($locked !== null && (int) $locked !== $supplierUserId) {
            throw new BusinessException(__('cart.limits.single_supplier_only'), 422);
        }
    }

    /** پس از افزودنِ موفق، تأمین‌کننده‌ی سبد را قفل می‌کند. */
    public function lockSupplier(Cart $cart, ?int $supplierUserId): void
    {
        if ($supplierUserId === null || $cart->{Cart::SUPPLIER_USER_ID} !== null) {
            return;
        }

        $cart->forceFill([Cart::SUPPLIER_USER_ID => $supplierUserId])->save();
    }

    /**
     * اسنپ‌شاتِ کامل سبد پیش از حذف — بند ۳.۱ و ۴.۲.
     *
     * سبدِ مهمان اسنپ‌شات نمی‌گیرد (`abandoned_carts.user_id` الزامی است و
     * گزارشِ «سبد رهاشده» بدون کاربر معنایی ندارد).
     */
    public function snapshot(Cart $cart): ?AbandonedCart
    {
        if ($cart->{Cart::USER_ID} === null) {
            return null;
        }

        $cart->loadMissing('items.productVariant');

        $items = $cart->items->map(fn (CartItem $item) => [
            'product_variant_id' => $item->{CartItem::PRODUCT_VARIANT_ID},
            // `data_get` و نه `?->`: رابطه در PHPDoc غیرnullable تایپ شده (و
            // larastan 3 روی L13 همین را می‌گیرد) ولی در زمان اجرا واریانتِ
            // حذف‌شده واقعاً null است — و اسنپ‌شات دقیقاً برای همان حالت وجود
            // دارد. `data_get` هم درست است هم بی‌صدا رد نمی‌شود.
            'product_name' => data_get($item, 'productVariant.product.name'),
            'variant_name' => data_get($item, 'productVariant.name'),
            'factory_id' => $item->{CartItem::FACTORY_ID},
            'factory_location_id' => $item->{CartItem::FACTORY_LOCATION_ID},
            'quantities' => $item->quantities,
            'quantity' => (float) $item->quantity,
            'unit_name' => $item->unit_name,
            'weight' => (float) $item->weight,
            'price' => (float) $item->{CartItem::PRICE},
            'final_price' => (float) $item->{CartItem::FINAL_PRICE},
            'total_with_vat' => (float) $item->{CartItem::TOTAL_WITH_VAT},
        ])->all();

        return AbandonedCart::create([
            AbandonedCart::USER_ID => $cart->{Cart::USER_ID},
            AbandonedCart::SUPPLIER_USER_ID => $cart->{Cart::SUPPLIER_USER_ID},
            AbandonedCart::ITEMS => $items,
            AbandonedCart::TOTAL_AMOUNT => $cart->items->sum(fn (CartItem $i) => (float) $i->{CartItem::TOTAL_WITH_VAT}),
            AbandonedCart::COUPON_CODE => $cart->{Cart::COUPON_CODE},
            AbandonedCart::CART_CREATED_AT => $cart->{Cart::CREATED_AT},
            AbandonedCart::EXPIRED_AT => now(),
        ]);
    }

    /**
     * آیا برای این سبد باید پیامکِ «۳۰ دقیقه مانده» فرستاده شود؟
     *
     * اگر `park_time_minutes` خودش از ۳۰ کمتر باشد هیچ هشداری نمی‌رود (بند ۳.۱)
     * — پیامکی که همزمان با ساختِ سبد یا پس از انقضایش برسد فقط هزینه است.
     */
    public function shouldWarn(Cart $cart): bool
    {
        if ($cart->{Cart::PARKED_UNTIL} === null || $cart->{Cart::PARK_WARNED_AT} !== null) {
            return false;
        }

        if ($this->settings->parkTime()->totalMinutes <= self::warningLeadMinutes()) {
            return false;
        }

        return $cart->{Cart::PARKED_UNTIL}->isBefore(now()->addMinutes(self::warningLeadMinutes()));
    }

    /** فاصله‌ی هشدار تا انقضا، به دقیقه (بند ۳.۱). */
    public static function warningLeadMinutes(): int
    {
        return 30;
    }
}
