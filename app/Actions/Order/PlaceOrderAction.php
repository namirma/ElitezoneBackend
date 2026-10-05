<?php

namespace App\Actions\Order;

use App\DataTransferObjects\Order\PlaceOrderData;
use App\DataTransferObjects\Shipping\ShipmentGroup;
use App\Events\Order\OrderPlaced;
use App\Exceptions\BusinessException;
use App\Exceptions\Order\CartIsEmptyException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CustomerType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit;
use App\Models\PriceFilterCategory;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\UserType;
use App\Services\DocumentSequenceService;
use App\Services\Financial\DebtEngine;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Marketing\CouponFundingService;
use App\Services\Marketing\CouponService;
use App\Services\PriceCalculationService;
use App\Services\PricingRoleResolver;
use App\Services\Shipping\CartShippingQuoteService;
use App\Services\Shipping\ShippingCostCalculator;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PlaceOrderAction
{
    public function __construct(
        private readonly PriceCalculationService $priceService,
        private readonly PricingRoleResolver $roleResolver,
        private readonly SettlementCalculatorService $settlementCalculator,
        private readonly DocumentSequenceService $sequenceService,
        private readonly ShippingCostCalculator $shippingCalculator,
        private readonly CartShippingQuoteService $shippingQuoteService,
        private readonly CouponService $couponService,
        private readonly DebtEngine $debtEngine,
        private readonly CouponFundingService $couponFunding,
    ) {}

    /**
     * سفارش را از روی سبد می‌سازد — **بدون کسر موجودی و بدون هیچ لِگ مالی**.
     *
     * بند ۳.۳: «رکورد Order فقط وقتی ساخته می‌شود که پول قطعی نشسته باشد.» پس
     * این متد دیگر یک اندپوینت نیست؛ از داخلِ تراکنشِ پرداخت صدا زده می‌شود
     * (`CheckoutController::pay` / `reserve`) و کسر موجودی کارِ همان مسیر است.
     *
     * چرا کسر موجودی از اینجا رفت (بند ۶.۲): سفارشِ `pending` موجودی را قفل
     * می‌کرد بدون آنکه یک ریال جابه‌جا شده باشد — هر کسی می‌توانست با ثبت
     * سفارش و نپرداختن، کالای انبار را برای دو ساعت از دسترس بقیه خارج کند.
     *
     * @param  string  $initialStatus  وضعیت اولیه — `processing` برای پرداخت کامل،
     *                                 `reserved` برای رزرو. هرگز `pending`.
     */
    /**
     * @param  User  $user  صاحبِ سبد و ثبت‌کننده‌ی سفارش.
     * @param  User|null  $payer  صاحبِ کیف پولی که پول از آن می‌رود (فاز ۶).
     *                            `null` یعنی حالتِ معمول: ثبت‌کننده خودش می‌پردازد.
     *                            در مسیرِ «الیت‌یار برای کارفرما» اینجا کارفرماست.
     */
    public function execute(
        User $user,
        PlaceOrderData $data,
        string $initialStatus = Order::STATUS_PROCESSING,
        ?User $payer = null,
    ): Order {
        // بدهکار سفارش تازه ثبت نمی‌کند (بند ۳.۱۲). گارد روی **پرداخت‌کننده**
        // است نه ثبت‌کننده: در مسیرِ «الیت‌یار برای کارفرما» پول از کیف پول
        // کارفرما می‌رود، پس بدهیِ اوست که باید مانع شود — بدهیِ الیت‌یار
        // ربطی به این سفارش ندارد.
        $this->assertNotInDebt($payer ?? $user);

        $order = DB::transaction(function () use ($user, $data, $initialStatus, $payer) {

            // ۱. خواندن و قفل کردن سبد خرید دقیقاً داخل تراکنش
            $cart = $this->getCartOrFail($user);

            // ۱.۵ گاردِ تک‌تأمین‌کننده — پیش از هر نوشتنی.
            $this->assertSingleSupplier($cart);
            $this->assertNoPriceUnderReview($cart);

            // ۱.۶ یک سفارش = یک مبدأ و یک مقصد (تصمیم مدیریت، بند ۳).
            $this->assertSingleOriginAndDestination($cart, $data->addressId);

            // ۲. محاسبه مبالغ
            $financials = $this->calculateOrderFinancials($cart, $user, $data);

            // ۲.۵ اعمال کوپن تخفیف (در صورت ارسال) — با lock تا مصرف همزمان نشود.
            // تخفیف روی final_amount نمایش داده می‌شود؛ leg مالی آن هنگام پرداخت
            // (WSSG→WO) ثبت می‌شود و فرمول‌های POO/PTS دست نمی‌خورند.
            // کد از بدنه‌ی درخواست، و در نبودش از خودِ سبد. تصمیمِ شاخه در
            // `CartCheckoutTotals` همیشه از `carts.coupon_code` می‌خواند؛ اگر
            // این‌جا فقط بدنه معتبر بود، سفارشی که فرانت کدش را نفرستاده (پیش‌نمایشِ
            // ناهم‌زمان بعد از رفرش) بدونِ تخفیف ساخته می‌شد و بیشتر از عددِ
            // نمایش‌داده‌شده از کیف پول کم می‌شد. `fromCart()` از قبل همین را می‌کرد.
            $couponCode = $data->couponCode ?: ($cart->{Cart::COUPON_CODE} ?: null);

            $coupon = null;
            if ($couponCode) {
                $coupon = $this->couponService->validateForCheckout($user, $couponCode, lock: true);
                $couponDiscount = $this->couponService->calculateDiscount($coupon, (float) $financials['final_amount']);
                // ── تأمینِ تخفیف (docs/specs/COUPON_FUNDING_2026-09-15.md §۳.۳) ──
                // تقسیمِ تخفیف بینِ تأمین‌کننده‌ها، سقفِ سهمِ شرکت/الیت‌یار در همین
                // سفارش و موجودیِ کیف پولِ هر تأمین‌کننده — پیش از هر نوشتنی؛ تخطی
                // ۴۲۲ با پیامِ دقیق است، نه کاهشِ بی‌صدا.
                $this->assertCouponFundable($coupon, $couponDiscount, $financials, $cart, ($payer ?? $user)->id);
                // ── پایانِ تأمینِ تخفیف ──
                $financials['coupon_id'] = $coupon->id;
                $financials['coupon_discount_amount'] = $couponDiscount;
                $financials['final_amount'] = max(0, $financials['final_amount'] - $couponDiscount);
            }

            // ۳. ایجاد سفارش + مرسوله‌ها (هر مبدأ→مقصد یک مرسوله) + اقلام
            $order = $this->createOrderRecord($user, $data, $financials, $cart, $initialStatus, $payer);

            if ($coupon) {
                $this->couponService->redeem($coupon);
            }
            $shipmentMap = $this->createShipments($order, $financials['shipments']);
            $this->createOrderItems($order, $cart, $financials['items_data'], $shipmentMap);

            // ۴. کسر از انبار **اینجا نیست** — بند ۶.۲.
            //
            // موجودی در لحظه‌ی پرداخت یا رزرو کسر می‌شود، نه در لحظه‌ی ساختِ
            // سفارش. صداکننده (`CheckoutController`) بلافاصله پس از این و در
            // همان تراکنش آن را انجام می‌دهد، پس بینِ ساخت و کسر هیچ فاصله‌ای
            // نیست — ولی مسئولیتش آنجاست، جایی که پول هم جابه‌جا می‌شود.

            // ۵. پاکسازی سبد خرید
            $this->clearCart($cart);

            return $order;
        });

        // ایونت بعد از commit ارسال می‌شود؛ صف با after_commit=false ممکن است
        // listener را قبل از commit اجرا کند و سفارش را پیدا نکند.
        OrderPlaced::dispatch($order);

        return $order;
    }

    private function getCartOrFail(User $user): Cart
    {
        // گام یک: قفل گرفتن روی سبد.
        // ترتیب باید دقیقاً همان Cart::queryForUser باشد که CartResolver با آن سبد
        // را به کاربر نشان می‌دهد؛ قبلاً اینجا latest('updated_at') بود و در حالتِ
        // دو-سبدی (پیش از ایندکس یکتای user_id) سفارش از سبدی غیر از سبدِ نمایش‌داده‌شده
        // ثبت می‌شد.
        $cart = Cart::queryForUser($user->id)
            ->lockForUpdate()
            ->first();

        if (! $cart) {
            throw new CartIsEmptyException(__('orders.errors.cart_empty'));
        }

        // گام دو: قفل گرفتن روی آیتم‌ها به طور جداگانه
        $items = CartItem::where('cart_id', $cart->id)
            ->lockForUpdate()
            ->with([
                'productVariant.product',
                'factory',
                'factoryLocation',
            ])
            ->get();

        if ($items->isEmpty()) {
            throw new CartIsEmptyException(__('orders.errors.cart_empty'));
        }

        $cart->load([
            'customerType',
            'badge',
            'priceFilterCategories',
        ]);

        // گام چهار: ست کردن آیتم‌ها روی سبد
        $cart->setRelation('items', $items);

        return $cart;
    }

    private function calculateOrderFinancials(Cart $cart, User $user, PlaceOrderData $data): array
    {
        $subtotal = 0.0;
        $totalVat = 0.0;
        $totalDiscount = 0.0;
        $totalWeight = 0.0;
        $goodsValue = 0.0;
        // ارزشِ کالا به قیمتِ **ثبت‌شده‌ی تأمین‌کننده** (پیش از فیلترهای نقش) — مبنای PTS (پرسشِ ۶).
        $supplierGoodsValue = 0.0;
        $itemsData = [];

        $roleCode = $this->roleResolver->resolve($user);
        $customerType = $cart->customerType ?? CustomerType::where('code', $roleCode)->first();

        $orderUnits = OrderUnit::allCached();

        $activeFilter = PriceFilterCategory::where('code', $roleCode)->first();
        $filterIds = $activeFilter ? [$activeFilter->id] : $cart->priceFilterCategories->pluck('id')->toArray();

        foreach ($cart->items as $item) {
            $pricing = $this->priceService->calculatePrice(
                variant: $item->productVariant,
                factory: $item->factory,
                location: $item->factoryLocation,
                customerType: $customerType,
                badge: $cart->badge,
                filterCategoryIds: $filterIds,
                withVAT: true,
                filterCategoryCode: $roleCode
            );

            // گاردِ قیمتِ صفر در مرزِ ثبت سفارش: اگر ردیف قیمتی برای این
            // (واریانت، کارخانه، محل تحویل) وجود نداشته باشد، پایپ‌لاین 0 برمی‌گرداند.
            // این گارد از تسویه‌ی سفارش با مبلغ صفر (checkout رایگان) جلوگیری می‌کند.
            if ($pricing->finalPrice <= 0) {
                throw ValidationException::withMessages([
                    'items' => __('cart.limits.price_unavailable', [
                        'name' => $item->productVariant->product->name ?? '',
                    ]),
                ]);
            }

            // Logistics weight + sales count — shared with the pre-checkout shipping
            // quote (CartShippingQuoteService) so the quoted freight equals the charge.
            $logistics = $this->shippingQuoteService->lineLogistics($item, $orderUnits);
            $itemLogisticsWeight = $logistics['weight'];
            $itemSalesQuantity = $logistics['count'];
            $primaryUnitName = $logistics['unit_name'];

            $totalWeight += $itemLogisticsWeight;

            // محاسبه قیمت‌های این ردیف
            $lineTotal = $itemLogisticsWeight * $pricing->finalPrice;
            $lineDiscount = $pricing->variantDiscountAmount * $itemLogisticsWeight;
            $lineVat = $itemLogisticsWeight * $pricing->vatAmount;

            // Subtotal should be the pre-discount value for correct accounting:
            $subtotal += ($lineTotal + $lineDiscount);
            $totalVat += $lineVat;
            $totalDiscount += $lineDiscount;

            // ارزشِ خالصِ کالا (پس از تخفیف، بدون مالیات و حمل) — مبنای official_price.
            // جدا از $subtotal انباشته می‌شود چون آن عمداً پیش-از-تخفیف است؛ گره‌زدنِ
            // یک عددِ مالی به نحوه‌ی حسابداریِ تخفیف، شکننده‌اش می‌کرد.
            $goodsValue += $lineTotal;
            $supplierGoodsValue += $itemLogisticsWeight * $pricing->supplierPrice;

            $itemsData[$item->id] = [
                'weight' => $itemLogisticsWeight,
                'quantity' => $itemSalesQuantity,
                'unit_name' => $primaryUnitName ?? 'واحد',
                'unit_price' => $pricing->finalPrice, // We keep the post-discount price per unit for the item line
                'total_price' => $lineTotal,
            ];
        }

        // هزینه حمل — هر قلم بر اساس کارخانه‌ی مبدأ و مقصدِ خودش گروه‌بندی (مرسوله) و قیمت‌گذاری
        // می‌شود. مقصدِ هر قلم: آدرسِ خودِ قلم (سبد چندمقصدی) و در نبود آن، آدرسِ اصلی سفارش.
        $primaryAddressId = $data->addressId;

        $neededAddressIds = [];
        foreach ($cart->items as $item) {
            $addrId = $item->address_id ?: $primaryAddressId;
            if ($addrId) {
                $neededAddressIds[(int) $addrId] = true;
            }
        }
        $addresses = Address::whereIn(Address::ID, array_keys($neededAddressIds))->get()->keyBy(Address::ID);

        $shippingLines = [];
        foreach ($cart->items as $item) {
            $addrId = $item->address_id ?: $primaryAddressId;
            $address = $addrId ? $addresses->get((int) $addrId) : null;

            $shippingLines[$item->id] = [
                'factory_id' => $item->factory_id ? (int) $item->factory_id : null,
                'factory_location_id' => $item->factory_location_id ? (int) $item->factory_location_id : null,
                'address_id' => $addrId ? (int) $addrId : null,
                'destination_province_id' => $address?->{Address::PROVINCE_ID} ? (int) $address->{Address::PROVINCE_ID} : null,
                'destination_city_id' => $address?->{Address::CITY_ID} ? (int) $address->{Address::CITY_ID} : null,
                // Both metrics are passed so the rate can bill by weight (kg) OR by count
                // (sales units) per the factory/supplier directive.
                'weight' => (float) ($itemsData[$item->id]['weight'] ?? 0),
                'count' => (float) ($itemsData[$item->id]['quantity'] ?? 0),
            ];
        }

        $shippingQuote = $this->shippingCalculator->quote($shippingLines);

        $shippingCost = $shippingQuote->total;
        foreach ($itemsData as $itemId => $line) {
            $itemsData[$itemId]['shipping_cost'] = (float) ($shippingQuote->perLine[$itemId] ?? 0.0);
        }

        // مالیاتِ کرایه — طبق فلگِ ±VAT قراردادِ تأمین‌کننده، همان فلگی که
        // ProcessPreScalePaymentAction هنگام پرداخت اسنپ‌شات می‌گیرد و POO با آن
        // حساب می‌شود.
        //
        // بدونِ این، `final_amount` مالیات را فقط روی کالا می‌بست در حالی که POO
        // آن را روی `Trans + OP×W` می‌بندد. تا وقتی هیچ نرخِ حملی وارد نشده بود
        // کرایه صفر می‌ماند و اختلاف پنهان بود؛ ولی از حدودِ کرایه = ۴٫۶٪ ارزشِ
        // کالا به بعد، POO از عددِ نمایش‌داده‌شده جلو می‌زند و خریدار **بیشتر** از
        // آنچه دیده پرداخت می‌کند.
        $transVat = $this->settlementCalculator->transVatFlagForSupplier(
            $cart->items->first()?->factory?->supplier_user_id
        );
        $shippingVat = $this->settlementCalculator->vatOnTrans(
            $shippingCost,
            $this->settlementCalculator->currentVatRate(),
            $transVat
        );
        $totalVat += $shippingVat;

        // ریالِ صحیح — **یک بار و همین‌جا**، نه ستون‌به‌ستون توسطِ MySQL.
        //
        // ستون‌های پول `decimal(*,0)`اند (`standardise_money_columns_to_integer_rials`)،
        // ولی این تابع تا امروز floatِ خام می‌داد (وزنِ ۳رقمی × قیمتِ ۴رقمی، کرایه‌ی
        // ۲رقمی) و هر ستون جداگانه در لحظه‌ی INSERT گرد می‌شد. پیامدها: مجموعِ
        // ستون‌های ذخیره‌شده تا ۱ ریال با `final_amount` نمی‌خواند، مدلِ در حافظه
        // (که همان تراکنش برای کوپن، سقفِ تأمین و کسر از کیف پول می‌خواند) با
        // ردیفِ ذخیره‌شده فرق داشت، و کوپنِ درصدی روی پایه‌ی اعشاری حساب می‌شد.
        // حالا اجزا اول گرد می‌شوند و `final` از اجزای گردشده ساخته می‌شود، پس
        // «Subtotal − Discount + Tax + Shipping = Final» روی خودِ ردیف هم برقرار است.
        // پیش‌نمایش (`CartCheckoutTotals::breakdownFor`) همین کار را با اجزای خودش
        // می‌کند؛ اختلافِ ≤۱ ریالِ دو تجزیه‌ی متفاوت پذیرفته است (تست‌ها delta=1).
        $subtotal = round($subtotal);
        $totalDiscount = round($totalDiscount);
        $totalVat = round($totalVat);
        $shippingCost = round($shippingCost);
        foreach ($itemsData as $itemId => $line) {
            $itemsData[$itemId]['unit_price'] = round($line['unit_price']);
            $itemsData[$itemId]['total_price'] = round($line['total_price']);
            $itemsData[$itemId]['shipping_cost'] = round($line['shipping_cost']);
        }

        // جمعِ حسابداریِ **پیش از تخفیفِ ویژه‌ی خریدار**:
        // Subtotal − Discount + Tax + Shipping = Gross
        $grossPayable = max(0, $subtotal - $totalDiscount + $totalVat + $shippingCost);

        $effectiveEliteYARId = $this->resolveEffectiveEliteYARId($user, $data);
        $eliteYAR = $effectiveEliteYARId ? User::find($effectiveEliteYARId) : null;

        $commissions = $this->calculateCommissions($eliteYAR, $subtotal);

        // ── official_price: میانگینِ وزنیِ ردیف‌ها ─────────────────────────────
        // تسویه با `OP × وزنِ کلِ سفارش` کار می‌کند، پس OP باید عددی باشد که این
        // ضرب، ارزشِ واقعیِ کالا را بدهد: OP = Σ(قیمت×وزن) / Σوزن.
        //
        // تا پیش از این هیچ‌کس این ستون را نمی‌نوشت، و هر سه مسیرِ تسویه روی
        // فالبکِ `items->first()->unit_price` می‌افتادند — یعنی **کلِ تناژِ سفارش
        // به قیمتِ ردیفِ اول** حساب می‌شد. برای سفارشِ تک‌ردیفی درست بود؛ برای
        // سبدی با دو سایز میلگرد از یک کارخانه (که گاردِ تک‌تأمین‌کننده اجازه‌اش
        // را می‌دهد) خریدار اشتباه صورتحساب می‌گرفت.
        //
        // با یک ردیف، این فرمول دقیقاً همان unit_price را می‌دهد — پس رفتار
        // سفارش‌های تک‌ردیفی ذره‌ای تغییر نمی‌کند.
        //
        // چهار رقمِ اعشار (از ۱۴۰۵/۰۷/۱۳)، نه ریالِ صحیح: گردکردنِ هر کیلو تا ۰٫۵ ریال
        // ضربدرِ وزن، POO را تا ۰٫۵ × وزن ریال از جمعِ اجزای نمایش‌داده‌شده دور می‌کرد
        // (۵٬۰۰۰ ریال روی ۱۰ تن) — و پاسخِ مدیریت به پرسشِ ۲ می‌خواهد مبلغِ فاکتور و
        // مبلغِ کسرشده **دقیقاً** یکی باشند. ستون هم `decimal(20,4)` شد.
        $officialPrice = $totalWeight > 0
            ? round(Money::toFloat(Money::div($goodsValue, $totalWeight), 4), 4)
            : 0.0;
        $supplierPrice = $totalWeight > 0
            ? round(Money::toFloat(Money::div($supplierGoodsValue, $totalWeight), 4), 4)
            : 0.0;

        // ── اسنپ‌شاتِ نرخ‌ها و مبلغِ قطعیِ خریدار — همین‌جا، لحظه‌ی ثبت ─────────────
        // پاسخ‌های مدیریت ۱۴۰۵/۰۷/۱۳:
        //   پرسشِ ۴ — %TC از قراردادِ تأمین‌کننده (NULL ⇒ سراسری).
        //   پرسشِ ۷ — تخفیفِ ویژه‌ی خریدار (%OW) فقط وقتی سفارش الیت‌یارِ تأییدشده دارد؛
        //             بی‌الیت‌یار، تخفیف و سهمِ الیت‌یار هر دو به شرکت می‌رسد.
        //   پرسشِ ۲ — `final_amount` همان مبلغی است که از کیف پول کم می‌شود (POO)، و
        //             تخفیفِ ویژه یک سطرِ جدا (`buyer_discount_amount`) است.
        // پیش از این نرخ‌ها لحظه‌ی **پرداخت** اسنپ‌شات می‌شدند و `final_amount` بدونِ %OW
        // بود، پس عددی که خریدار می‌دید با عددی که کسر می‌شد نمی‌خواند
        // (`PooVersusDisplayedAmountTest` همان اختلاف را «قابلِ توضیح» می‌پنداشت).
        // پرداخت حالا همین اسنپ‌شات را می‌خواند (`getCommissionRatesForOrder`) — نه نرخِ
        // زنده — پس نمایش و کسر یک محاسبه‌اند.
        $supplierUserId = $cart->items->first()?->factory?->supplier_user_id;
        $supplierUserId = $supplierUserId !== null ? (int) $supplierUserId : null;
        // نبودِ پروفایل یعنی `true` — همان پیش‌فرضِ `ProcessPreScalePaymentAction`.
        $transCommission = $this->settlementCalculator->transCommissionFlagForSupplier($supplierUserId);

        $tcPercentage = $this->settlementCalculator->tcPercentageForSupplier($supplierUserId);
        $badgeCode = $this->badgeCodeOf($eliteYAR);
        $rates = $this->settlementCalculator->getCommissionRatesForTc($badgeCode, $tcPercentage);
        $vatRate = $this->settlementCalculator->currentVatRate();
        // الیت‌یارِ مؤثر همیشه تأییدشده است (`resolveEffectiveEliteYARId`)، پس «دارد» کافی است.
        $owApplied = $effectiveEliteYARId !== null ? (float) $rates['ow_final_rate'] : 0.0;

        // تخفیفِ ویژه = POO(بی‌OW) − POO(با OW) روی همان فرمول — همان عددی که پیش‌نمایشِ
        // سبد (`CartCheckoutTotals`) نشان می‌دهد؛ مبلغِ قطعی = Gross − تخفیف، و پرداخت
        // دقیقاً همین را کم می‌کند (`placedPooFor`)، نه بازمحاسبه‌ی فرمول. بی‌الیت‌یار
        // تخفیف صفر است (پرسشِ ۷).
        $buyerDiscount = $this->settlementCalculator->buyerDiscountAmount(
            $shippingCost, $officialPrice, $totalWeight, $owApplied, $vatRate, $transCommission, $transVat
        );
        $finalAmount = max(0, $grossPayable - $buyerDiscount);

        return [
            'subtotal' => $subtotal,
            'vat_amount' => $totalVat,
            'total_discount' => $totalDiscount,
            'shipping_cost' => $shippingCost,
            'gross_payable' => $grossPayable,
            'buyer_discount_amount' => $buyerDiscount,
            'final_amount' => $finalAmount,
            'total_weight' => $totalWeight,
            'official_price' => $officialPrice,
            'supplier_price' => $supplierPrice,
            'commissions' => $commissions,
            'effective_elite_yar_id' => $effectiveEliteYARId,
            'items_data' => $itemsData,
            'shipments' => $shippingQuote->shipments,
            // ستون‌های اسنپ‌شات (غیرِ fillable؛ `createOrderRecord` با forceFill می‌نویسد).
            'rate_snapshot' => [
                Order::COMMISSION_RATE => $tcPercentage,
                Order::VAT_RATE => $vatRate,
                Order::OWNER_DISCOUNT_RATE => $owApplied,
                Order::SAZE_SHARE_RATE => $rates['ssg_final_rate'],
                Order::ELITE_YAR_SHARE_RATE => $rates['co_final_rate'],
                Order::OW_SHARE_PERCENT => $rates['ow_percent_of_tc'],
                Order::CO_SHARE_PERCENT => $rates['co_percent_of_tc'],
                Order::TRANS_COMMISSION => $transCommission,
                Order::TRANS_VAT => $transVat,
            ],
            'coupon_inputs' => [
                'tc' => $tcPercentage,
                'badge_code' => $badgeCode,
                'vat' => $vatRate,
                'trans_commission' => $transCommission,
                'trans_vat' => $transVat,
                'ow_applied' => $owApplied,
                'supplier_user_id' => $supplierUserId,
            ],
        ];
    }

    /**
     * الیت‌یارِ مؤثرِ سفارش — پاسخ‌های مدیریت به پرسش‌های ۱ و ۸ (۱۴۰۵/۰۷/۱۳).
     *
     * — ثبت‌کننده خودش الیت‌یار است (به هر سه نامِ نقش): الیت‌یار = خودش، **فقط اگر
     *   تأییدشده باشد**. الیت‌یارِ هولد (بی‌لایسنسِ سازه ۹۰) سهم و تخفیفِ الیت‌یاری
     *   ندارد، پس سفارشش بی‌الیت‌یار ثبت می‌شود (StoreOrderRequest خریدِ برای
     *   کارفرما را از او می‌گیرد). وقتی برای خودش می‌خرد سهمی نمی‌گیرد
     *   (`WeighbridgeVatRouter`: الیت‌یار = پرداخت‌کننده ⇒ سهم به شرکت)، ولی
     *   تخفیفِ ویژه‌ی خریدار را می‌گیرد — قیمتِ با تخفیف روی خریدِ خودش (پرسشِ ۱).
     * — کارفرما: الیت‌یارِ فرستاده‌شده؛ اگر نفرستاده و دقیقاً **یک** الیت‌یارِ تأییدشده
     *   دارد، خودکار همان (پرسشِ ۸، گزینه‌ی الف). با چند تا StoreOrderRequest ۴۲۲ داده
     *   است؛ این‌جا هم دفاعِ دوم برای صداکننده‌های غیرِ HTTP.
     *
     * نتیجه: `elite_yar_user_id` روی سفارش همیشه یا خالی است یا **تأییدشده** — همین
     * یک حقیقت است که `calculateOrderFinancials` برای تخفیفِ ویژه می‌خواند.
     */
    private function resolveEffectiveEliteYARId(User $user, PlaceOrderData $data): ?int
    {
        if ($user->isEliteYAR()) {
            return $user->isVerifiedEliteYAR() ? $user->id : null;
        }

        if ($data->eliteYARUserId !== null) {
            return $data->eliteYARUserId;
        }

        $verified = $user->verifiedEliteYARs();

        if ($verified->count() > 1) {
            throw ValidationException::withMessages([
                'elite_yar_user_id' => __('orders.store_elite_yar_choice_required'),
            ]);
        }

        return $verified->first()?->id;
    }

    /**
     * محاسبه کمیسیون بر اساس الیت یار مؤثر سفارش.
     *
     * @param  User|null  $eliteYAR  الیت‌یارِ مؤثرِ سفارش (پس از تعیین نقش)، یا null
     */
    private function calculateCommissions(?User $eliteYAR, float $subtotal): array
    {
        $fees = ['engineer' => 0, 'contractor' => 0];

        if (! $eliteYAR) {
            return $fees;
        }

        $badgeCode = $this->badgeCodeOf($eliteYAR);
        $calculated = $this->settlementCalculator->calculateCommissions($subtotal, $badgeCode);
        $amount = $calculated['CO'] ?? 0;

        $engineerTypeId = Cache::remember('user_type_id_engineer', 86400,
            fn () => UserType::where('slug', 'engineer')->value('id'));
        $contractorTypeId = Cache::remember('user_type_id_contractor', 86400,
            fn () => UserType::where('slug', 'contractor')->value('id'));

        if ($eliteYAR->hasRole('engineer') || $eliteYAR->{User::TYPE_ID} === $engineerTypeId) {
            $fees['engineer'] = $amount;
        } elseif ($eliteYAR->hasRole('contractor') || $eliteYAR->{User::TYPE_ID} === $contractorTypeId) {
            $fees['contractor'] = $amount;
        }

        return $fees;
    }

    /** کدِ نشانِ الیت‌یار برای نرخِ سهم — بی‌الیت‌یار یا بی‌نشان ⇒ `pioneer` (نرخِ پیش‌فرض). */
    private function badgeCodeOf(?User $eliteYAR): string
    {
        $badge = $eliteYAR?->badge;

        return $badge ? (string) ($badge->getAttribute('code') ?: 'pioneer') : 'pioneer';
    }

    private function createOrderRecord(User $user, PlaceOrderData $data, array $fins, Cart $cart, string $initialStatus, ?User $payer = null): Order
    {
        // ۱. تشخیص کانتکست (Context) کالا بر اساس اولین آیتم سبد خرید
        // می‌توانید منطق شرط را بر اساس ساختار دیتابیس خودتان (مثلاً نام دسته‌بندی) تنظیم کنید
        $firstItemCategory = $cart->items->first()->productVariant->product->category_code ?? '';
        $context = (strtoupper($firstItemCategory) === 'BRICK')
            ? DocumentSequenceService::CTX_BRICK
            : DocumentSequenceService::CTX_REBAR;

        // ۲. تولید شماره سفارش هوشمند، یکتا و استاندارد (مثال: EZ-ORD-RB-140502-70300100)
        $orderNumber = $this->sequenceService->generate(
            DocumentSequenceService::TYPE_ORDER,
            $context,
            Order::class,
            Order::ORDER_NUMBER
        );

        // ۳. ثبت نهایی در دیتابیس
        $order = Order::create([
            Order::PLACER_USER_ID => $user->id,
            // payer = صاحبِ کیف پولی که پول از آن می‌رود. صریح نوشته می‌شود حتی
            // وقتی با ثبت‌کننده یکی است، تا هیچ سفارشِ تازه‌ای به fallbackِ
            // `payerUserId()` تکیه نکند — آن fallback فقط برای ردیف‌های قدیمی است.
            Order::PAYER_USER_ID => ($payer ?? $user)->id,
            // beneficiary = گیرنده سفارش (فقط در مسیر الیت یار که برای کارفرما خریداری می‌کند)
            Order::BENEFICIARY_USER_ID => $data->beneficiaryUserId,
            // eliteYAR = الیت یار که کمیسیون CO می‌گیرد (مؤثر — computed در calculateOrderFinancials)
            Order::ELITE_YAR_USER_ID => $fins['effective_elite_yar_id'],
            Order::ADDRESS_ID => $data->addressId,
            // بدون fallback به id=1: StoreOrderRequest این فیلد را required + exists
            // می‌کند، پس رسیدنِ null اینجا یعنی یک صداکننده‌ی غیرHTTP قرارداد را نقض
            // کرده. «?? 1» آن باگ را پشتِ یک رکوردِ دلخواه پنهان می‌کرد (و اگر رکورد ۱
            // وجود نداشت، به یک خطای FK مبهم می‌رسید).
            'shipping_method_id' => $data->shippingMethodId,
            Order::ORDER_NUMBER => $orderNumber, // استفاده از شماره تولید شده
            Order::STATUS => $initialStatus,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,

            Order::SUBTOTAL => $fins['subtotal'],
            Order::DISCOUNT_AMOUNT => $fins['total_discount'],
            Order::COUPON_ID => $fins['coupon_id'] ?? null,
            Order::COUPON_DISCOUNT_AMOUNT => $fins['coupon_discount_amount'] ?? 0,
            Order::VAT_AMOUNT => $fins['vat_amount'],
            Order::SHIPPING_COST => $fins['shipping_cost'],
            Order::FINAL_AMOUNT => $fins['final_amount'],
            Order::WEIGHT_BEFORE_SCALE => $fins['total_weight'],

            Order::ENGINEER_FEE_AMOUNT => $fins['commissions']['engineer'],
            Order::CONTRACTOR_FEE_AMOUNT => $fins['commissions']['contractor'],
            Order::ENGINEER_FEE_PAYMENT_STATUS => $fins['commissions']['engineer'] > 0 ? Order::FEE_PAYMENT_PENDING : null,
            Order::CONTRACTOR_FEE_PAYMENT_STATUS => $fins['commissions']['contractor'] > 0 ? Order::FEE_PAYMENT_PENDING : null,

            Order::RESERVATION_EXPIRES_AT => now()->addHours((int) config('financial.pending_order_ttl_hours', 2)),
            Order::NOTES => $data->description,
        ]);

        // `official_price` و بقیه‌ی ستون‌های اسنپ‌شاتِ مالی عمداً در $fillable نیستند، پس
        // گذاشتنشان در آرایه‌ی create بالا **بی‌صدا دور ریخته می‌شد** — همان تله‌ای که
        // در این پروژه چند بار زده. forceFill مسیرِ مجازِ نوشتنِ این ستون‌هاست.
        $order->forceFill(array_merge($fins['rate_snapshot'], [
            Order::OFFICIAL_PRICE => $fins['official_price'],
            Order::SUPPLIER_PRICE => $fins['supplier_price'],
            Order::BUYER_DISCOUNT_AMOUNT => $fins['buyer_discount_amount'],
        ]))->save();

        return $order;
    }

    /**
     * Create one Shipment per (origin → destination) leg and return a map of
     * cart item id → shipment id so order items can be linked to their shipment.
     *
     * @param  ShipmentGroup[]  $shipments
     * @return array<int|string, int>
     */
    private function createShipments(Order $order, array $shipments): array
    {
        $map = [];

        foreach ($shipments as $group) {
            $shipment = new Shipment([
                Shipment::ORDER_ID => $order->id,
                Shipment::FACTORY_ID => $group->factoryId,
                Shipment::FACTORY_LOCATION_ID => $group->factoryLocationId,
                Shipment::ADDRESS_ID => $group->addressId,
                Shipment::DESTINATION_PROVINCE_ID => $group->destinationProvinceId,
                Shipment::DESTINATION_CITY_ID => $group->destinationCityId,
                Shipment::WEIGHT => $group->weight,
                Shipment::BILLED_COUNT => $group->count,
                Shipment::COST_BASIS => $group->costBasis,
                Shipment::SHIPPING_COST => $group->cost,
                Shipment::STATUS => Shipment::STATUS_PENDING,
            ]);

            // اسنپ‌شاتِ نرخ — همان الگوی `orders.commission_rate`. باسکول کرایه را
            // از این دو ستون با وزنِ واقعی بازمحاسبه می‌کند؛ بدونِ آن ویرایشِ
            // `shipping_rates` پس از ثبت، سفارشی با وزنِ یکسان را بی‌رضایت شارژ می‌کرد.
            $shipment->forceFill([
                Shipment::RATE_FLAT_COST => $group->rateFlatCost,
                Shipment::RATE_UNIT_COST => $group->rateUnitCost,
            ])->save();

            foreach ($group->lineKeys as $cartItemId) {
                $map[$cartItemId] = $shipment->id;
            }
        }

        return $map;
    }

    /**
     * سفارش باید فقط یک تأمین‌کننده داشته باشد — تا زمانی که تسویه چندخطی نوشته شود.
     *
     * سبد خرید عمداً چندمبدأی است (کل زیرسیستم Shipment برای همین ساخته شده و
     * AddToCartAction هر factory_id را می‌پذیرد) و این متد هر آیتم را با
     * supplier_user_id مخصوص خودش ثبت می‌کند. اما *تسویه‌ی مالی* یک‌خطی است: هر پنج
     * نقطه‌ی تسویه با `items->first()` کار می‌کنند —
     * ProcessPreScalePaymentAction، ProcessReservationCompletionAction،
     * WeighbridgeVatRouter، SupplierDebtHandler و WeighbridgeReconciler.
     *
     * پس یک سفارشِ دوتأمین‌کننده‌ای امروز این‌طور تسویه می‌شود:
     *   • کلِ PTS به تأمین‌کننده‌ی آیتمِ اول می‌رود؛ تأمین‌کننده‌ی دوم **هیچ** نمی‌گیرد.
     *   • مالیات امانی و بدهیِ کسری هم فقط روی تأمین‌کننده‌ی اول ثبت می‌شود.
     *   • حتی مبلغِ خریدار هم غلط است: POO با `قیمتِ آیتم اول × وزنِ کل` حساب
     *     می‌شود، پس سبدِ «میلگرد + آجر» کلِ وزن را با نرخِ میلگرد صورت‌حساب می‌کند.
     *
     * تسویه‌ی چندخطی تغییرِ بزرگی است؛ تا آن‌موقع، ثبتِ چنین سفارشی متوقف می‌شود.
     * همان قاعده‌ای که برای weight_before_scale برقرار است: داده‌ی نامعتبر باید
     * متوقف کند، نه پول جابه‌جا کند. با آمدنِ تسویه‌ی چندخطی این گارد برداشته شود.
     */
    /**
     * بدهکار سفارش تازه ثبت نمی‌کند — بند ۳.۱۲ سند.
     *
     * پیش از تراکنش اجرا می‌شود، مثل باقی گاردهای «داده نامعتبر باید متوقف کند،
     * نه اینکه پول جابه‌جا شود»: ثبتِ سفارشی که بعداً قابل پرداخت نیست فقط
     * موجودی را قفل می‌کند و سبد را خالی.
     */
    private function assertNotInDebt(User $payer): void
    {
        if ($this->debtEngine->isInDebt((int) $payer->id)) {
            throw new BusinessException(__('orders.blocked_by_debt'), 422);
        }
    }

    /**
     * یک سفارش = یک مبدأ و یک مقصد — بند ۳ سند، تصمیم مدیریت.
     *
     * گاردِ سبد (`CartParkService`) زودتر جلوی این را می‌گیرد، ولی این یکی
     * **قاعده‌ی واقعی** است و حذف‌شدنی نیست: `UpdateCartItemAction` می‌تواند
     * مقصدِ یک ردیف را بعداً عوض کند، و هر فراخوانی که مستقیم به این اکشن
     * برسد اصلاً از مسیرِ سبد رد نشده است. همان دلیلی که
     * `assertSingleSupplier` هم در هر دو جا هست.
     *
     * مقصدِ خالیِ یک ردیف یعنی «آدرسِ اصلیِ سفارش»، پس پیش از مقایسه به همان
     * تبدیل می‌شود — وگرنه سبدی که یک ردیفش آدرس صریح دارد و بقیه ندارند،
     * دومقصدی به نظر می‌رسید در حالی که هر دو به یک جا می‌روند.
     *
     * ‏`$primaryAddressId` می‌تواند `null` باشد: مسیرِ «کارفرما سبدِ الیت‌یار را
     * می‌پردازد» آدرس را از خودِ سبد می‌خواند و آن ستون nullable است. در آن حالت
     * فقط آدرس‌های **صریحِ** ردیف‌ها با هم سنجیده می‌شوند.
     *
     * پیش از هر نوشتنی اجرا می‌شود: داده‌ی نامعتبر باید متوقف کند، نه اینکه
     * موجودی قفل و سبد خالی شود.
     */
    private function assertSingleOriginAndDestination(Cart $cart, ?int $primaryAddressId): void
    {
        $origins = $cart->items
            ->map(fn (CartItem $item) => $item->{CartItem::FACTORY_LOCATION_ID})
            ->filter()
            ->unique()
            ->values();

        if ($origins->count() > 1) {
            throw new BusinessException(__('orders.multiple_origins_not_supported'), 422);
        }

        $destinations = $cart->items
            ->map(fn (CartItem $item) => $item->{CartItem::ADDRESS_ID} ?? $primaryAddressId)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($destinations->count() > 1) {
            throw new BusinessException(__('orders.multiple_destinations_not_supported'), 422);
        }
    }

    /**
     * کالایی که قیمتش در صفِ تأییدِ ادمین است فروخته نمی‌شود — نه با قیمتِ قدیم.
     *
     * `AddToCartAction` ورودِ تازه را می‌بندد؛ این گارد آیتمی را می‌گیرد که
     * **پیش از** ثبتِ درخواست در سبد نشسته بود (یا سبدی که الیت‌یار بسته و
     * کارفرما دیرتر می‌پردازد). تأمین‌کننده خودش گفته این قیمت درست نیست؛
     * فروختنِ همان قیمت دقیقاً ضرری است که صف برای جلوگیری از آن ساخته شد.
     */
    private function assertNoPriceUnderReview(Cart $cart): void
    {
        $variantIds = $cart->items->pluck(CartItem::PRODUCT_VARIANT_ID)->filter()->unique()->all();

        if ($variantIds === []) {
            return;
        }

        $blocked = ProductVariant::query()
            ->whereIn(ProductVariant::ID, $variantIds)
            ->whereHas('pendingPriceChanges')
            ->exists();

        if ($blocked) {
            throw new BusinessException(__('supplier.price_under_review'), 422);
        }
    }

    /**
     * تأمینِ تخفیف — سقف و موجودیِ هر تأمین‌کننده در **همین** سفارش
     * (docs/specs/COUPON_FUNDING_2026-09-15.md §۳.۳).
     *
     * سهم‌ها با همان ورودی‌هایی حساب می‌شوند که `ProcessPreScalePaymentAction` بعداً
     * واقعاً به POO/PTS می‌دهد: قیمتِ رسمی، وزنِ پیش از باسکول، کرایه، فلگ‌های
     * قراردادِ تأمین‌کننده، نرخِ مالیاتِ زنده و نشانِ الیت‌یار. شرطِ «الیت‌یارِ
     * کمیسیون‌گیر» عیناً همانِ `WeighbridgeVatRouter::routeEliteYAR` است: ست‌شده و
     * غیر از پرداخت‌کننده.
     */
    private function assertCouponFundable(Coupon $coupon, float $discount, array $fins, Cart $cart, int $payerId): void
    {
        if ($discount <= 0) {
            return;
        }

        $inputs = $fins['coupon_inputs'];
        $supplierId = $inputs['supplier_user_id'];
        $eliteYARId = $fins['effective_elite_yar_id'] ? (int) $fins['effective_elite_yar_id'] : null;
        $hasCommissionedEliteYAR = $eliteYARId !== null && $eliteYARId !== $payerId;

        // همان ورودی‌هایی که اسنپ‌شاتِ سفارش با آن‌ها ساخته شد — یک محاسبه، نه دو.
        $caps = $this->settlementCalculator->couponFundingCaps(
            trans: (float) $fins['shipping_cost'],
            op: (float) $fins['official_price'],
            weight: (float) $fins['total_weight'],
            badgeCode: $inputs['badge_code'],
            vatPercent: (float) $inputs['vat'],
            transCommission: (bool) $inputs['trans_commission'],
            transVat: (bool) $inputs['trans_vat'],
            hasCommissionedEliteYAR: $hasCommissionedEliteYAR,
            tcPercentage: (float) $inputs['tc'],
            supplierOp: (float) $fins['supplier_price'],
            owAppliedPercent: (float) $inputs['ow_applied'],
        );

        $this->couponFunding->plan($coupon, $discount, $caps, $supplierId, $hasCommissionedEliteYAR ? $eliteYARId : null);
    }

    private function assertSingleSupplier(Cart $cart): void
    {
        $supplierIds = $cart->items
            ->map(fn ($item) => $item->factory?->supplier_user_id)
            ->filter()
            ->unique()
            ->values();

        if ($supplierIds->count() > 1) {
            throw new BusinessException(__('orders.multiple_suppliers_not_supported'));
        }
    }

    private function createOrderItems(Order $order, Cart $cart, array $itemsData, array $shipmentMap): void
    {
        foreach ($cart->items as $item) {
            // An order line must map to a real supplier — the supplier wallet (WS) is
            // settled by this ID. Defaulting to the system user (the old `?? 1`) would
            // misattribute payouts, so surface the data problem instead of hiding it.
            $supplierId = $item->factory?->supplier_user_id;
            if (! $supplierId) {
                // پیام کاربر باید فارسی و از lang بیاید (قانون: هیچ رشته‌ی
                // کاربرپسندی در PHP هاردکد نشود). جزئیات فنی فقط در لاگ می‌رود —
                // خریدار نباید نام ستون دیتابیس را در پاسخ چک‌اوت ببیند.
                Log::error('Checkout blocked: factory has no supplier_user_id', [
                    'cart_item_id' => $item->id,
                    'factory_id' => $item->factory_id,
                    'variant_id' => $item->product_variant_id,
                ]);

                throw new BusinessException(
                    __('checkout.supplier_not_found_for_factory', [
                        // ‏`cart_items.factory_id` در اسکیما NOT NULL است، پس رابطه
                        // هرگز غایب نیست — nullsafe اینجا هیچ‌وقت کاری نمی‌کرد.
                        'factory' => (string) $item->factory->name ?: '—',
                    ]),
                    422
                );
            }

            $calc = $itemsData[$item->id] ?? [
                'weight' => 0,
                'quantity' => 0,
                'unit_name' => '',
                'unit_price' => 0,
                'total_price' => 0,
                'shipping_cost' => 0,
            ];

            OrderItem::create([
                OrderItem::ORDER_ID => $order->id,
                OrderItem::SHIPMENT_ID => $shipmentMap[$item->id] ?? null,
                'product_variant_id' => $item->product_variant_id,
                'supplier_user_id' => $supplierId,
                // Preserve the line's origin so per-factory freight can be reconstructed.
                OrderItem::FACTORY_ID => $item->factory_id,
                OrderItem::FACTORY_LOCATION_ID => $item->factory_location_id,
                'product_name' => $item->productVariant->product->name ?? 'Unknown',
                'variant_sku' => $item->productVariant->sku,

                'quantity_details' => $item->quantities,
                'quantity' => $calc['quantity'],
                'weight' => $calc['weight'],
                'unit_name' => $calc['unit_name'],

                'unit_price' => $calc['unit_price'],
                'total_price' => $calc['total_price'],
                OrderItem::SHIPPING_COST => $calc['shipping_cost'] ?? 0,
            ]);
        }
    }

    private function clearCart(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->delete();
    }
}
