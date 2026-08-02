<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\DataTransferObjects\Pricing\CartPricingResult;
use App\DataTransferObjects\Pricing\PricingContextData;
use App\Exceptions\Order\CartIsEmptyException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit;
use App\Models\PriceFilterCategory;
use App\Models\User;
use App\Services\Cart\CartPriceValidityResolver;
use App\Services\Cart\CartResolver;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\Pricing\CartPricingCalculator;
use App\Services\Shipping\CartShippingQuoteService;
use App\Support\Money;

/**
 * پیش‌فاکتور (Doc 0) از سبدِ **هنوز ثبت‌نشده** — بدون ساختِ سفارش و بدون رکورد Invoice.
 *
 * تا پیش از این پیش‌فاکتور فقط بعد از `POST /checkout` قابل دانلود بود
 * (`GET /profile/orders/{order}/invoices/initial`)، یعنی خریدار برای گرفتنِ یک
 * برگهٔ قیمت مجبور بود اول سفارشِ واقعی ثبت کند — که موجودی کم می‌کند، مهلتِ
 * دو ساعته می‌گیرد و بعد باید لغو شود. این اکشن همان سند را از خودِ سبد می‌سازد.
 *
 * ## چرا یک `Order`ِ درون‌حافظه‌ای؟
 *
 * `InvoiceDataBuilder` و قالبِ `pdf.invoice_initial` هر دو با `Order` کار می‌کنند.
 * دو راه بود: (الف) یک مسیرِ دومِ ساختِ داده مخصوصِ سبد، (ب) ساختِ یک `Order`ِ
 * ذخیره‌نشده با همان شکلِ فیلدهایی که `PlaceOrderAction` می‌نویسد. راهِ (الف)
 * یعنی دو پیاده‌سازیِ موازی از «فاکتور چطور جمع زده می‌شود» که با هم واگرا
 * می‌شوند — دقیقاً همان الگویی که در این پروژه بارها هزینه داده. پس راهِ (ب):
 * **هیچ‌چیز ذخیره نمی‌شود** و PDFِ خروجی همان چیزی است که بعد از ثبتِ سفارش هم
 * صادر خواهد شد.
 *
 * ## قیمت‌ها از کجا می‌آیند
 *
 * از `CartPricingCalculator` — همان سرویسی که `CartResponseBuilder` برای ساختِ
 * `totals`ِ پاسخِ سبد استفاده می‌کند. پس عددِ روی PDF دقیقاً همان عددی است که
 * کاربر در صفحهٔ سبد می‌بیند، نه یک بازمحاسبهٔ مستقل.
 *
 * ## هزینهٔ حمل
 *
 * فقط وقتی وارد پیش‌فاکتور می‌شود که `address_id` داده شود — چون کرایه بدونِ
 * مقصد تعریف نشده است. بدونِ آدرس، ردیفِ حمل صفر است و مبلغ، مبلغِ کالا + مالیات.
 * محاسبه از `CartShippingQuoteService` می‌آید که `PlaceOrderAction` هم از آن
 * استفاده می‌کند، پس برآورد با کرایهٔ واقعی برابر است.
 *
 * @see \Tests\Feature\Cart\CartPreInvoiceTest
 */
class BuildCartPreInvoiceAction
{
    public function __construct(
        private readonly CartPricingCalculator $cartPricing,
        private readonly CartResolver $resolver,
        private readonly CartShippingQuoteService $shippingQuoteService,
        private readonly CartPriceValidityResolver $priceValidity,
        private readonly SettlementCalculatorService $settlementCalculator,
        private readonly InvoiceGenerator $invoiceGenerator,
    ) {}

    /**
     * @param  int|null  $addressId  مقصد، برای آنکه کرایه هم در پیش‌فاکتور بیاید
     * @return \Mpdf\Mpdf|\Mccarlosen\LaravelMpdf\LaravelMpdf آبجکت PDF (ذخیره نمی‌شود)
     *
     * @throws CartIsEmptyException
     */
    public function execute(Cart $cart, User $user, ?int $addressId = null): mixed
    {
        return $this->invoiceGenerator->previewPreInvoice(
            $this->previewOrderFor($cart, $user, $addressId),
            $this->previewNumber($cart)
        );
    }

    /**
     * سفارشِ درون‌حافظه‌ایِ پشتِ PDF — بدونِ رندر.
     *
     * public است تا بتوان **عددها** را مستقل از PDF پین کرد: مهم‌ترین خاصیتِ این
     * اندپوینت آن است که مبلغِ روی پیش‌فاکتور با `totals.total`ِ پاسخِ سبد یکی
     * باشد، و سنجیدنِ آن از داخلِ یک PDF عملاً ناممکن است.
     *
     * @throws CartIsEmptyException
     */
    public function previewOrderFor(Cart $cart, User $user, ?int $addressId = null): Order
    {
        $cart->loadMissing([
            'items.productVariant.product',
            'items.factory',
            'items.factoryLocation',
            'customerType',
            'badge',
            'priceFilterCategories',
        ]);

        if ($cart->items->isEmpty()) {
            throw new CartIsEmptyException(__('checkout.cart_is_empty'));
        }

        $pricing = $this->cartPricing->calculate($cart, $this->pricingContext($cart));

        $address = $addressId
            ? Address::whereKey($addressId)->first()
            : null;

        $shippingCost = $addressId
            ? $this->shippingQuoteService->quoteForCart($cart, $addressId)->total
            : 0.0;

        // مالیاتِ کرایه، دقیقاً با همان قاعده‌ای که PlaceOrderAction به کار می‌برد —
        // وگرنه پیش‌فاکتور و فاکتورِ نهاییِ همان سبد دو عدد متفاوت می‌دادند.
        $shippingVat = $this->settlementCalculator->vatOnTrans(
            $shippingCost,
            $this->settlementCalculator->currentVatRate(),
            $this->settlementCalculator->transVatFlagForSupplier(
                $cart->items->first()?->factory?->supplier_user_id
            )
        );

        return $this->buildTransientOrder($cart, $user, $pricing, $address, $shippingCost, $shippingVat);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /** همان زمینهٔ قیمت‌گذاریِ CartResponseBuilder — تا PDF و صفحهٔ سبد یک عدد بدهند. */
    private function pricingContext(Cart $cart): PricingContextData
    {
        $activeCode = $this->resolver->activeCode();

        $pfId = PriceFilterCategory::where(PriceFilterCategory::CODE, $activeCode)->value('id');
        $filterIds = $pfId ? [$pfId] : $cart->priceFilterCategories->pluck('id')->all();

        return new PricingContextData(
            activeCode: $activeCode,
            customerType: $cart->customerType,
            badge: $cart->badge,
            filterCategoryIds: $filterIds,
            withVat: true,
            offerBasis: 'weight',
        );
    }

    /**
     * سفارشِ ذخیره‌نشده‌ای که فقط برای رندرِ PDF زندگی می‌کند.
     *
     * شکلِ فیلدهای مالی عمداً **مو‌به‌مو** همان چیزی است که `PlaceOrderAction`
     * می‌نویسد، تا `InvoiceDataBuilder` همان‌طور جمع بزند که برای سفارشِ واقعی:
     *
     *   subtotal        = ارزشِ کالا **پیش از** تخفیف
     *   discount_amount = تخفیفِ واریانت + تخفیفِ آفرِ سبد
     *   final_amount    = subtotal − discount + vat + shipping
     *
     * (`CartPricingCalculator` زیرمجموعه را **پس از** تخفیفِ واریانت می‌دهد، پس
     *  تخفیف دوباره به آن اضافه می‌شود تا معادلهٔ بالا برقرار بماند.)
     */
    private function buildTransientOrder(
        Cart $cart,
        User $user,
        CartPricingResult $pricing,
        ?Address $address,
        float $shippingCost,
        float $shippingVat = 0.0,
    ): Order {
        $subtotalBeforeDiscount = $pricing->subtotal + $pricing->totalVariantDiscount;
        $discountAmount = $pricing->totalVariantDiscount + $pricing->offerDiscount;
        $vatAmount = $pricing->vatAmount + $shippingVat;
        $finalAmount = max(0.0, $subtotalBeforeDiscount - $discountAmount + $vatAmount + $shippingCost);

        $order = new Order;

        $order->forceFill([
            Order::ORDER_NUMBER => null,
            Order::SUBTOTAL => $subtotalBeforeDiscount,
            Order::DISCOUNT_AMOUNT => $discountAmount,
            Order::VAT_AMOUNT => $vatAmount,
            Order::SHIPPING_COST => $shippingCost,
            Order::FINAL_AMOUNT => $finalAmount,
            // پیش‌فاکتور همیشه پرداخت‌نشده است — برچسبِ وضعیت روی سند از همین می‌آید.
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
            // «تاریخ اعتبار»ِ روی سند: اگر مهلتِ اعتبارِ قیمت اعلام شده باشد همان،
            // وگرنه InvoiceDataBuilder به «۲ روز پس از صدور» برمی‌گردد.
            Order::RESERVATION_EXPIRES_AT => $this->priceValidity->resolve($cart),
        ]);

        // ⚠️ همهٔ relationها باید دستی نشانده شوند: مدل ذخیره نشده و کلیدِ اصلی
        // ندارد، پس هر lazy-load یک کوئریِ `where ..._id is null` می‌زند.
        $order->setRelation('placer', $user->loadMissing('profile'));
        $order->setRelation('address', $address?->loadMissing(['city', 'province']));
        $order->setRelation('coupon', null);
        $order->setRelation('items', $this->buildTransientItems($cart, $pricing));

        return $order;
    }

    /**
     * @return \Illuminate\Support\Collection<int, OrderItem>
     */
    private function buildTransientItems(Cart $cart, CartPricingResult $pricing): \Illuminate\Support\Collection
    {
        $linesById = collect($pricing->lines)->keyBy(fn ($line) => $line->cartItemId);
        $orderUnits = OrderUnit::allCached();

        return $cart->items
            ->map(function ($cartItem) use ($linesById, $orderUnits) {
                $line = $linesById->get($cartItem->id);

                if (! $line) {
                    return null;
                }

                $logistics = $this->shippingQuoteService->lineLogistics($cartItem, $orderUnits);

                $item = new OrderItem;

                $item->forceFill([
                    OrderItem::PRODUCT_VARIANT_ID => $cartItem->product_variant_id,
                    OrderItem::PRODUCT_NAME => $cartItem->productVariant?->product?->name,
                    OrderItem::WEIGHT => $line->baseQuantity,
                    OrderItem::QUANTITY => $logistics['count'],
                    OrderItem::UNIT_NAME => $logistics['unit_name'] ?? 'واحد',
                    // قیمتِ واحد پس از تخفیفِ واریانت — همان چیزی که PlaceOrderAction
                    // در `unit_price` می‌نویسد، پس دو سند شبیه هم درمی‌آیند.
                    OrderItem::UNIT_PRICE => $line->unitPrice,
                    OrderItem::TOTAL_PRICE => (float) Money::mul($line->baseQuantity, $line->unitPrice),
                ]);

                $item->setRelation('productVariant', $cartItem->productVariant);

                return $item;
            })
            ->filter()
            ->values();
    }

    /**
     * شناسهٔ پیش‌نمایش — عمداً **شمارهٔ سند نیست**.
     *
     * شماره‌های واقعی (`EZ-QTE-RB-140502-70300100`) از `DocumentSequenceService`
     * می‌آیند و با ذخیره‌شدنِ رکورد رزرو می‌شوند. اینجا چیزی ذخیره نمی‌شود، پس اگر
     * از همان سرویس شماره می‌گرفتیم، همان عدد بعداً به یک سندِ واقعیِ دیگر می‌رسید
     * و مشتری دو مدرکِ متفاوت با یک شماره در دست داشت. پیشوندِ `PRE-` این را
     * غیرممکن می‌کند و برای کاربر هم صریح است که این برگه هنوز سند نیست.
     */
    private function previewNumber(Cart $cart): string
    {
        return 'PRE-'.verta()->format('Ymd').'-'.$cart->id;
    }
}
