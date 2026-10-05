<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\Order;
use App\Models\PriceSetting;
use App\Models\SupplierProfile;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Financial\PooVersusDisplayedAmountTest;

/**
 * Financial settlement calculator.
 *
 * Implements all four POO / PTS formula variants defined in the purchase-process
 * design document ("پروسه خرید"), selected by two per-supplier contract flags:
 *
 *   +%TC (transCommission=true)  — commission is deducted from Trans as well as
 *                                   the product price
 *   -%TC (transCommission=false) — Trans is excluded from commission deduction
 *   +VAT (transVat=true)         — VAT is applied to Trans
 *   -VAT (transVat=false)        — Trans is excluded from VAT
 *
 * The current default %TC rate is 1 % of the invoice subtotal (ex-VAT).
 * This is a per-supplier contract parameter (`supplier_profiles.commission_percent`,
 * management Q4 1405/07/13 — `tcPercentageForSupplier()`); a super-admin edits it in the
 * supplier profile, and the global `price_settings.tc_percentage` is the default.
 *
 * Price bases (management Q6, 1405/07/13): PTS is computed from the SUPPLIER's own
 * registered price (`orders.supplier_price`, `supplierOpFor()`), POO from the buyer's
 * post-filter price (`orders.official_price`). The buyer discount %OW is applied only
 * when the order has a verified EliteYAR (Q7) — `orders.owner_discount_rate` holds the
 * APPLIED rate (0 without one), `ow_share_percent` the share definition.
 * The rate breakdown per badge:
 *
 *   %TC = %OW + %CO + %SSG      (%OW = 40 %, %CO+%SSG = 60 %)
 *
 *   Badge     %CO      %SSG
 *   Normal    25 %     35 %
 *   Pioneer   25 %     35 %
 *   Gold     37.5 %   22.5 %
 *   Silver   32.5 %   27.5 %
 *   Bronze    30 %     30 %
 */
class SettlementCalculatorService
{
    /** نرخِ پیش‌فرضِ کارمزدِ تراکنش (٪) — وقتی تنظیمِ سراسری ست نشده باشد. */
    private const TC_PERCENTAGE = 1.0;

    /**
     * مرزِ خروجیِ هر مبلغ: **ریالِ صحیح**.
     *
     * ریال زیرواحد ندارد، و مهم‌تر: هر ستونی که این اعداد در آن می‌نشیند —
     * `wallets.balance`, `wallets.balance_hold`, `wallet_transactions.amount` و
     * (از این پس) ستون‌های مبلغیِ `orders` — `decimal(20,0)` است.
     *
     * ‏`Money::toFloat()` پیش‌فرضش **دو رقم اعشار** است، پس POO/PTS می‌توانستند
     * `1234567.89` برگردانند: سفارش آن را با اعشار ذخیره می‌کرد و کیف پول همان
     * لِگ را به `1234568` گرد می‌کرد. اسنپ‌شاتِ سفارش و دفترِ کیف پول تا یک ریال
     * از هم فاصله می‌گرفتند — و چون هر دو طرف با خودشان سازگار بودند،
     * ‏`financial:doctor` هم چیزی نمی‌دید.
     *
     * همان استدلالی که `extractVatInRials()` را ساخت، فقط این‌بار سراسری:
     * **در دقتی حساب کن که واقعاً ذخیره می‌شود.**
     *
     * ⚠️ `extractVat()` عمداً بیرونِ این قاعده است — قراردادِ دورقمی‌اش مستند و
     * تست‌شده است و نسخه‌ی ریالی‌اش `extractVatInRials()` است.
     */
    private static function rial(string|float $value): float
    {
        return Money::toFloat($value, 0);
    }

    /**
     * نرخِ مؤثرِ %TC: از تنظیمِ سراسریِ قابل‌ویرایش (PriceSetting: tc_percentage) خوانده
     * می‌شود و در نبودِ آن به پیش‌فرضِ ۱٪ برمی‌گردد. static است تا از متدهای static و
     * instanceِ این سرویس یکسان قابل‌فراخوانی باشد. مقدار کش‌شده است (کشِ PriceSetting).
     */
    private static function tcPercentage(): float
    {
        return PriceSetting::getValueAsFloat(PriceSetting::CODE_TC_PERCENTAGE, self::TC_PERCENTAGE);
    }

    /**
     * نرخِ مؤثرِ %TC برای اسنپ‌شات روی سفارش.
     *
     * پرداخت باید همین مقدار را در `orders.commission_rate` ثبت کند و باسکول باید
     * همان را بخواند — نه اینکه دوباره از PriceSetting زنده بخواند. پیش از این
     * ProcessPreScalePaymentAction عددِ هاردکدِ `1.0` را ثبت می‌کرد و
     * WeighbridgeReconciler نرخ را زنده می‌خواند؛ اگر tc_percentage بین پرداخت و
     * باسکول تغییر می‌کرد، POOM با وزنِ کاملاً یکسان از POO فاصله می‌گرفت و یک
     * «کسری» کاذب می‌ساخت که واقعاً به خریدار عودت می‌داد و برای تأمین‌کننده بدهی
     * ثبت می‌کرد.
     */
    public function currentTcPercentage(): float
    {
        return self::tcPercentage();
    }

    /** کلیدِ کشِ کارمزدِ قراردادیِ یک تأمین‌کننده؛ `SupplierProfile::booted()` با هر ذخیره پاکش می‌کند. */
    public static function supplierTcCacheKey(int $supplierUserId): string
    {
        return "supplier_tc:{$supplierUserId}";
    }

    /**
     * %TC مؤثر برای سفارش‌های یک تأمین‌کننده — پاسخِ مدیریت به پرسشِ ۴ (۱۴۰۵/۰۷/۱۳):
     * «درصد کارمزد مطابق قرارداد تامین کننده در بک آفیس ثبت می‌شود»؛ بی‌قرارداد
     * (NULL) همان مقدارِ سراسری. کش‌شده چون پایپ‌لاینِ قیمت برای هر ردیفِ لیستِ
     * محصولات آن را می‌خواند (کفِ قیمت، `ApplyPriceFilters`).
     */
    public function tcPercentageForSupplier(?int $supplierUserId): float
    {
        if ($supplierUserId === null) {
            return self::tcPercentage();
        }

        $override = Cache::remember(self::supplierTcCacheKey($supplierUserId), 600, function () use ($supplierUserId): string {
            $value = SupplierProfile::where(SupplierProfile::USER_ID, $supplierUserId)
                ->value(SupplierProfile::COMMISSION_PERCENT);

            // رشته‌ی sentinel به‌جای null — `Cache::remember` مقدارِ null را نگه نمی‌دارد.
            return $value === null ? '' : (string) $value;
        });

        return $override === '' ? self::tcPercentage() : (float) $override;
    }

    /**
     * قیمتِ مبنای **تأمین‌کننده** (PTS): `supplier_price` و برای سفارش‌های پیش از این
     * قاعده `official_price` (همان رفتارِ قدیم: PTS از قیمتِ خریدار). پرسشِ ۶ مدیریت.
     */
    public function supplierOpFor(Order $order, float $buyerOp): float
    {
        $supplierOp = (float) ($order->{Order::SUPPLIER_PRICE} ?? 0);

        return $supplierOp > 0 ? $supplierOp : $buyerOp;
    }

    /**
     * مبلغِ تخفیفِ ویژه‌ی خریدار به ریال — تفاوتِ POO با و بدونِ %OW روی همان فرمول و همان
     * پرچم‌های ±TC/±VAT. یک تعریف برای پیش‌نمایشِ سبد (op = ارزشِ خالصِ کالا، وزن = ۱) و
     * ثبتِ سفارش (op = official_price، وزن = وزنِ سفارش)، تا سطرِ «تخفیف ویژه‌ی خریدار»
     * در سبد، سفارش و فاکتور یک عدد باشد (پرسشِ ۲ مدیریت). صفر بی‌الیت‌یار (پرسشِ ۷).
     */
    public function buyerDiscountAmount(
        float $trans,
        float $op,
        float $weight,
        float $owAppliedPercent,
        float $vatPercent,
        bool $transCommission,
        bool $transVat,
    ): float {
        if ($owAppliedPercent <= 0.0) {
            return 0.0;
        }

        $withoutDiscount = $this->calculatePOO($trans, $op, $weight, 0.0, $vatPercent, $transCommission, $transVat);
        $withDiscount = $this->calculatePOO($trans, $op, $weight, $owAppliedPercent, $vatPercent, $transCommission, $transVat);

        return max(0.0, $withoutDiscount - $withDiscount);
    }

    /**
     * POOِ یک سفارشِ **ثبت‌شده با اسنپ‌شات** (از ۱۴۰۵/۰۷/۱۳): همان مبلغی که به خریدار نشان
     * داده شده — `final_amount + coupon_discount_amount` — نه بازمحاسبه‌ی فرمول.
     *
     * پرسشِ ۲ مدیریت: عددِ فاکتور با مبلغِ پرداختی یکی. ثبتِ سفارش `final_amount` را از
     * همین فرمول می‌سازد (با %OW اعمال‌شده) و اجزا را روی آن می‌بندد؛ اگر پرداخت دوباره
     * فرمول را اجرا کند، گردکردنِ مستقلِ اجزا تا ۲ ریال فاصله می‌اندازد. `null` یعنی سفارشِ
     * بی‌اسنپ‌شات (پیش از این قاعده) — صداکننده فرمول را اجرا می‌کند، همان رفتارِ قدیم.
     */
    public function placedPooFor(Order $order): ?float
    {
        if ((float) ($order->{Order::COMMISSION_RATE} ?? 0) <= 0.0) {
            return null;
        }

        return self::rial(Money::add(
            (float) $order->{Order::FINAL_AMOUNT},
            (float) ($order->{Order::COUPON_DISCOUNT_AMOUNT} ?? 0)
        ));
    }

    /**
     * همان نرخ‌های getCommissionRates اما با یک %TC مشخص (اسنپ‌شاتِ سفارش) به‌جای
     * مقدارِ زنده‌ی PriceSetting. هر محاسبه‌ی *بعد از* پرداخت باید از این استفاده کند.
     *
     * @return array{
     *   ow_percent_of_tc: float, co_percent_of_tc: float, ssg_percent_of_tc: float,
     *   ow_final_rate: float, co_final_rate: float, ssg_final_rate: float,
     * }
     */
    public function getCommissionRatesForTc(?string $badgeCode, float $tcPercentage): array
    {
        return $this->ratesFromShares($this->badgeShares($badgeCode), $tcPercentage);
    }

    /**
     * نرخ‌های یک سفارشِ **پرداخت‌شده** — هم %TC و هم سهم‌های OW/CO از اسنپ‌شاتِ خودِ
     * سفارش، هرگز از تنظیمِ زنده. هر محاسبه‌ی پس از پرداخت (باسکول) باید از این
     * استفاده کند.
     *
     * از `a411c4f` سهم‌ها در پنل قابلِ ویرایش‌اند؛ `getCommissionRatesForTc` فقط %TC
     * را از اسنپ‌شات می‌گرفت و سهم‌ها را زنده می‌خواند، پس ویرایشِ سهمِ OW بینِ پرداخت
     * و باسکول POOM را با وزنِ یکسان از POO دور می‌کرد — همان باگِ قدیمیِ %TC.
     *
     * سفارشِ بدونِ اسنپ‌شاتِ سهم (پرداخت‌شده پیش از این تغییر) با **پیش‌فرض‌های
     * کاتالوگ** حساب می‌شود، نه مقدارِ زنده: همان اعدادی که پیش از قابلِ‌ویرایش‌شدنِ
     * سهم‌ها در کد ثابت بودند و آن سفارش‌ها با آن‌ها پرداخت شدند.
     *
     * @return array{
     *   ow_percent_of_tc: float, co_percent_of_tc: float, ssg_percent_of_tc: float,
     *   ow_final_rate: float, co_final_rate: float, ssg_final_rate: float, ow_applied_rate: float,
     * }
     */
    public function getCommissionRatesForOrder(Order $order, ?string $badgeCode): array
    {
        $tc = (float) ($order->{Order::COMMISSION_RATE} ?? 0);

        // بی‌اسنپ‌شاتِ %TC: سفارش‌های خیلی قدیمی — همان رفتارِ سازگاریِ قبلی (زنده).
        if ($tc <= 0) {
            $live = $this->getCommissionRates($badgeCode);
            $live['ow_applied_rate'] = $live['ow_final_rate'];

            return $live;
        }

        $ow = $order->{Order::OW_SHARE_PERCENT};
        $co = $order->{Order::CO_SHARE_PERCENT};

        $shares = ($ow !== null && $co !== null)
            ? ['ow' => (float) $ow, 'co' => (float) $co, 'ssg' => (float) Money::sub(Money::sub('100', (string) $ow), (string) $co)]
            : $this->defaultBadgeShares($badgeCode);

        $rates = $this->ratesFromShares($shares, $tc);

        // %OW **اعمال‌شده** روی این سفارش — پرسشِ ۷ مدیریت (۱۴۰۵/۰۷/۱۳): بی‌الیت‌یار صفر.
        // `owner_discount_rate` (NOT NULL DEFAULT 0) همیشه کنارِ `commission_rate` نوشته
        // می‌شود (ثبتِ سفارش از ۱۴۰۵/۰۷/۱۳، و پیش از آن لحظه‌ی پرداخت با همان ow_final_rate)،
        // پس برای هر سفارشِ اسنپ‌شات‌دار معتبر است. POO و POOM باید از همین بخوانند، نه از
        // `ow_final_rate` — وگرنه باسکول روی سفارشِ بی‌الیت‌یار تخفیفی را دوباره اعمال
        // می‌کرد که هرگز داده نشده بود و با وزنِ یکسان یک «کسریِ» کاذب می‌ساخت.
        $rates['ow_applied_rate'] = (float) $order->{Order::OWNER_DISCOUNT_RATE};

        return $rates;
    }

    /**
     * @param  array{ow: float, co: float, ssg: float}  $shares
     * @return array{
     *   ow_percent_of_tc: float, co_percent_of_tc: float, ssg_percent_of_tc: float,
     *   ow_final_rate: float, co_final_rate: float, ssg_final_rate: float,
     * }
     */
    private function ratesFromShares(array $shares, float $tcPercentage): array
    {
        return [
            'ow_percent_of_tc' => $shares['ow'],
            'co_percent_of_tc' => $shares['co'],
            'ssg_percent_of_tc' => $shares['ssg'],
            'ow_final_rate' => (float) Money::mul($tcPercentage, Money::div($shares['ow'], '100')),
            'co_final_rate' => (float) Money::mul($tcPercentage, Money::div($shares['co'], '100')),
            'ssg_final_rate' => (float) Money::mul($tcPercentage, Money::div($shares['ssg'], '100')),
        ];
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Calculate commissions for the initial order placement snapshot.
     * Used by PlaceOrderAction / ProcessPreScalePaymentAction.
     *
     * @param  float  $subtotal  مبلغ کل بدون مالیات
     * @param  string|null  $badgeCode  کد نشان الیت یار (golden, silver, bronze, pioneer, null)
     * @return array{TC: float, OW: float, CO: float, SSG: float}
     */
    public function calculateCommissions(float $subtotal, ?string $badgeCode): array
    {
        $rates = $this->getCommissionRates($badgeCode);

        // bcmath throughout — no intermediate float accumulation. Commissions are
        // whole Rials, so the boundary rounds to 0 decimals (preserves prior round()).
        $TC = Money::mul($subtotal, Money::div(self::tcPercentage(), '100'));
        $OW = Money::mul($TC, Money::div($rates['ow_percent_of_tc'], '100'));
        $CO = Money::mul($TC, Money::div($rates['co_percent_of_tc'], '100'));
        $SSG = Money::mul($TC, Money::div($rates['ssg_percent_of_tc'], '100'));

        return [
            'TC' => Money::toFloat($TC, 0),
            'OW' => Money::toFloat($OW, 0),
            'CO' => Money::toFloat($CO, 0),
            'SSG' => Money::toFloat($SSG, 0),
        ];
    }

    /**
     * Calculate POO — Payment Of Owner (مبلغ پرداختی خریدار).
     *
     * Uses the initial weight (WBS) for the pre-scale payment, or
     * the actual weighbridge weight (WAS) for the modified POOM calculation.
     * The formula variant is selected by the supplier-contract flags.
     *
     * Four variants:
     *   +TC +VAT: [Trans + OP×W] × (1−%OW) × (1+VAT)
     *   +TC −VAT: [OP×W × (1−%OW) × (1+VAT)] + [Trans × (1−%OW)]
     *   −TC +VAT: [Trans + OP×W × (1−%OW)] × (1+VAT)
     *   −TC −VAT: [OP×W × (1−%OW) × (1+VAT)] + Trans
     *
     * @param  float  $trans  هزینه حمل و نقل
     * @param  float  $op  قیمت رسمی (OP)
     * @param  float  $weight  وزن (WBS قبل از باسکول، یا WAS بعد از باسکول)
     * @param  float  $owPercent  %OW به صورت درصد نهایی (مثال: ۰.۴ برای ۰.۴٪)
     * @param  float  $vatPercent  %VAT به صورت درصد (مثال: ۱۰ برای ۱۰٪)
     * @param  bool  $transCommission  آیا کمیسیون روی Trans اعمال می‌شود؟
     * @param  bool  $transVat  آیا VAT روی Trans اعمال می‌شود؟
     */
    public function calculatePOO(
        float $trans,
        float $op,
        float $weight,
        float $owPercent,
        float $vatPercent,
        bool $transCommission = true,
        bool $transVat = true,
    ): float {
        $owFactor = Money::percentSub($owPercent);   // 1 − %OW/100
        $vatFactor = Money::percentAdd($vatPercent);  // 1 + VAT/100
        $productBase = Money::mul($op, $weight);

        if ($transCommission && $transVat) {
            // +TC +VAT: [Trans + OP×W] × (1−%OW) × (1+VAT)
            return self::rial(Money::mul(Money::mul(Money::add($trans, $productBase), $owFactor), $vatFactor));
        }

        if ($transCommission) {
            // +TC −VAT: [OP×W × (1−%OW) × (1+VAT)] + [Trans × (1−%OW)]
            return self::rial(Money::add(
                Money::mul(Money::mul($productBase, $owFactor), $vatFactor),
                Money::mul($trans, $owFactor)
            ));
        }

        if ($transVat) {
            // −TC +VAT: [Trans + OP×W × (1−%OW)] × (1+VAT)
            return self::rial(Money::mul(Money::add($trans, Money::mul($productBase, $owFactor)), $vatFactor));
        }

        // −TC −VAT: [OP×W × (1−%OW) × (1+VAT)] + Trans
        return self::rial(Money::add(Money::mul(Money::mul($productBase, $owFactor), $vatFactor), $trans));
    }

    /**
     * Calculate PTS — Payment To Supplier (مبلغ پرداختی به سوپلایر).
     *
     * Uses the initial weight (WBS) for the pre-scale payment, or
     * the actual weighbridge weight (WAS) for the modified PTSM calculation.
     *
     * Four variants (same transport-flag logic as POO, but TC = OW+SSG+CO):
     *   +TC +VAT: [Trans + OP×W] × (1−TC) × (1+VAT)
     *   +TC −VAT: [OP×W × (1−TC) × (1+VAT)] + [Trans × (1−TC)]
     *   −TC +VAT: [Trans + OP×W × (1−TC)] × (1+VAT)
     *   −TC −VAT: [OP×W × (1−TC) × (1+VAT)] + Trans
     *
     * @param  float  $trans  هزینه حمل و نقل
     * @param  float  $op  قیمت رسمی
     * @param  float  $weight  وزن
     * @param  float  $owPercent  %OW نهایی
     * @param  float  $ssgPercent  %SSG نهایی
     * @param  float  $coPercent  %CO نهایی
     * @param  float  $vatPercent  %VAT
     * @param  bool  $transCommission  آیا کمیسیون روی Trans اعمال می‌شود؟
     * @param  bool  $transVat  آیا VAT روی Trans اعمال می‌شود؟
     */
    public function calculatePTS(
        float $trans,
        float $op,
        float $weight,
        float $owPercent,
        float $ssgPercent,
        float $coPercent,
        float $vatPercent,
        bool $transCommission = true,
        bool $transVat = true,
    ): float {
        $totalDeductionPercent = $owPercent + $ssgPercent + $coPercent;
        $deductFactor = Money::percentSub($totalDeductionPercent); // 1 − (OW+SSG+CO)/100
        $vatFactor = Money::percentAdd($vatPercent);               // 1 + VAT/100
        $productBase = Money::mul($op, $weight);

        if ($transCommission && $transVat) {
            // +TC +VAT: [Trans + OP×W] × (1−TC) × (1+VAT)
            return self::rial(Money::mul(Money::mul(Money::add($trans, $productBase), $deductFactor), $vatFactor));
        }

        if ($transCommission) {
            // +TC −VAT: [OP×W × (1−TC) × (1+VAT)] + [Trans × (1−TC)]
            return self::rial(Money::add(
                Money::mul(Money::mul($productBase, $deductFactor), $vatFactor),
                Money::mul($trans, $deductFactor)
            ));
        }

        if ($transVat) {
            // −TC +VAT: [Trans + OP×W × (1−TC)] × (1+VAT)
            return self::rial(Money::mul(Money::add($trans, Money::mul($productBase, $deductFactor)), $vatFactor));
        }

        // −TC −VAT: [OP×W × (1−TC) × (1+VAT)] + Trans
        return self::rial(Money::add(Money::mul(Money::mul($productBase, $deductFactor), $vatFactor), $trans));
    }

    /**
     * Return commission-rate percentages for a given badge.
     *
     * Returns two sets of rates:
     *  - `*_percent_of_tc`  — share as a % of TC (used in calculateCommissions)
     *  - `*_final_rate`     — absolute % of the invoice total (used in
     *                          calculatePOO / calculatePTS as owPercent / ssgPercent / coPercent)
     *
     * @return array{
     *   ow_percent_of_tc: float,
     *   co_percent_of_tc: float,
     *   ssg_percent_of_tc: float,
     *   ow_final_rate: float,
     *   co_final_rate: float,
     *   ssg_final_rate: float,
     * }
     */
    public function getCommissionRates(?string $badgeCode): array
    {
        $shares = $this->badgeShares($badgeCode);

        return [
            // Percentages relative to TC (for commission-amount calculations)
            'ow_percent_of_tc' => $shares['ow'],
            'co_percent_of_tc' => $shares['co'],
            'ssg_percent_of_tc' => $shares['ssg'],

            // Absolute percentages of invoice total (for POO / PTS formula inputs)
            // Example: TC=1 %, OW=40 % of TC → ow_final_rate = 0.4 %
            'ow_final_rate' => (float) Money::mul(self::tcPercentage(), Money::div($shares['ow'], '100')),
            'co_final_rate' => (float) Money::mul(self::tcPercentage(), Money::div($shares['co'], '100')),
            'ssg_final_rate' => (float) Money::mul(self::tcPercentage(), Money::div($shares['ssg'], '100')),
        ];
    }

    /**
     * Share-of-TC percentages per badge (always sum to 100 %).
     *
     * NOTE: the canonical badge codes seeded in the DB are gold / silver / bronze
     * (see database/seeders/ProductSeeder.php). 'golden' is accepted as an alias
     * so a legacy value never silently falls through to the default rate.
     *
     * @return array{ow: float, co: float, ssg: float}
     */
    private function badgeShares(?string $badgeCode): array
    {
        // سهم‌ها از price_settings (پنل: پارامترهای مالی) — پیش‌فرض‌ها همان اعدادِ
        // هاردکدِ سابق. SSG باقی‌مانده است تا جمع همیشه ۱۰۰ بماند.
        $coCode = match (strtolower(trim($badgeCode ?? ''))) {
            'gold', 'golden' => PriceSetting::CODE_CO_SHARE_GOLD,
            'silver' => PriceSetting::CODE_CO_SHARE_SILVER,
            'bronze' => PriceSetting::CODE_CO_SHARE_BRONZE,
            default => PriceSetting::CODE_CO_SHARE_DEFAULT, // normal / pioneer
        };
        $catalog = PriceSetting::catalog();
        $ow = PriceSetting::getValueAsFloat(PriceSetting::CODE_OW_SHARE, (float) $catalog[PriceSetting::CODE_OW_SHARE]['default']);
        $co = PriceSetting::getValueAsFloat($coCode, (float) $catalog[$coCode]['default']);

        return ['ow' => $ow, 'co' => $co, 'ssg' => (float) Money::sub(Money::sub('100', $ow), $co)];
    }

    /**
     * همان سهم‌ها از **پیش‌فرضِ کاتالوگ** — اعدادی که پیش از `a411c4f` در کد ثابت بودند.
     *
     * @return array{ow: float, co: float, ssg: float}
     */
    private function defaultBadgeShares(?string $badgeCode): array
    {
        $coCode = match (strtolower(trim($badgeCode ?? ''))) {
            'gold', 'golden' => PriceSetting::CODE_CO_SHARE_GOLD,
            'silver' => PriceSetting::CODE_CO_SHARE_SILVER,
            'bronze' => PriceSetting::CODE_CO_SHARE_BRONZE,
            default => PriceSetting::CODE_CO_SHARE_DEFAULT,
        };
        $catalog = PriceSetting::catalog();
        $ow = (float) $catalog[PriceSetting::CODE_OW_SHARE]['default'];
        $co = (float) $catalog[$coCode]['default'];

        return ['ow' => $ow, 'co' => $co, 'ssg' => (float) Money::sub(Money::sub('100', $ow), $co)];
    }

    /**
     * درصد مالیات مؤثر برای یک سفارش — **تنها منبع مجاز**.
     *
     * ⚠️ سه محل (`ProcessPreScalePaymentAction`, `ProcessReservationCompletionAction`,
     * `WeighbridgeReconciler`) هر کدام جداگانه `$order->vat_rate ?? 10.0` می‌نوشتند و
     * دو مشکل داشت:
     *
     * ۱. **`??` هیچ‌وقت کار نمی‌کرد.** ستون `orders.vat_rate` از نوع
     *    `decimal(5,2) NOT NULL DEFAULT 0.00` است و هیچ‌کس پیش از پرداخت پرش
     *    نمی‌کند، پس مقدارش `0.00` بود — نه `null`. یعنی مسیر خرید مستقیم و
     *    بازمحاسبه‌ی باسکول POO/PTS را با **مالیات صفر** حساب می‌کردند، در حالی که
     *    سبد خرید از خریدار مالیات ۱۰٪ گرفته بود؛ و چون `sazeVat = gross × 0`
     *    می‌شد، هولد امانیِ مالیات (WVAT) هرگز ثبت نمی‌شد و اسناد O/P/Q عملاً
     *    صفر آزاد می‌کردند. (مسیر تکمیل رزرو `?:` نوشته بود و درست کار می‌کرد —
     *    یعنی یک سفارش بسته به مسیر پرداخت، مالیات متفاوتی می‌خورد.)
     *
     * ۲. **عدد ۱۰ هاردکد بود** در سه جا، در حالی که نرخ واقعی در
     *    `price_settings.vat_rate` است و همان چیزی است که پایپ‌لاین قیمت‌گذاری
     *    (`ApplyVat`) استفاده می‌کند. با تغییر نرخ، تسویه از سبد خرید واگرا می‌شد.
     *
     * اسنپ‌شاتِ واقعیِ روی سفارش (مقدار مثبت) مقدم است تا بازاجرای یک عملیات،
     * نرخِ لحظه‌ی پرداخت را عوض نکند؛ در نبودش نرخ زنده‌ی تنظیمات خوانده می‌شود، و
     * اگر آن هم ست نشده باشد `financial.default_vat_rate` (env: `DEFAULT_VAT_RATE`).
     *
     * ترتیب: اسنپ‌شاتِ سفارش ← تنظیماتِ زنده‌ی قیمت ← پیش‌فرضِ کانفیگ.
     * هیچ عددی اینجا هاردکد نیست.
     */
    public function resolveVatRate(Order $order): float
    {
        $snapshot = (float) ($order->{Order::VAT_RATE} ?? 0);

        return $snapshot > 0
            ? $snapshot
            : $this->currentVatRate();
    }

    /**
     * نرخِ مالیاتِ **زنده** — برای لحظاتی که هنوز سفارشی وجود ندارد که اسنپ‌شات
     * داشته باشد (ثبتِ سفارش، پیش‌فاکتورِ سبد).
     *
     * همان دو حلقه‌ی آخرِ `resolveVatRate()` است و عمداً از آن جدا نشده تا زنجیره‌ی
     * «تنظیماتِ زنده‌ی قیمت ← پیش‌فرضِ کانفیگ» یک‌بار نوشته شود؛ کپی‌شدنِ همین
     * زنجیره در سه اکشن، همان باگی بود که این کلاس برای جمع‌کردنش ساخته شد.
     */
    public function currentVatRate(): float
    {
        return PriceSetting::getValueAsFloat(
            PriceSetting::CODE_VAT_RATE,
            (float) config('financial.default_vat_rate', 10.0)
        );
    }

    /**
     * فلگِ ±VAT قراردادِ تأمین‌کننده: «آیا مالیات روی کرایه هم بسته می‌شود؟»
     *
     * نبودِ پروفایل یعنی `true` — همان پیش‌فرضی که
     * `ProcessPreScalePaymentAction` و `ProcessReservationCompletionAction`
     * هنگام اسنپ‌شات‌گرفتن استفاده می‌کنند، تا مبلغی که پیش از پرداخت به خریدار
     * نشان داده می‌شود با فرمولی که بعداً واقعاً اجرا می‌شود یکی باشد.
     */
    public function transVatFlagForSupplier(?int $supplierUserId): bool
    {
        if ($supplierUserId === null) {
            return true;
        }

        return (bool) (SupplierProfile::where(SupplierProfile::USER_ID, $supplierUserId)
            ->value(SupplierProfile::TRANS_VAT) ?? true);
    }

    /** فلگِ ±TC قراردادِ تأمین‌کننده: «آیا کارمزد روی کرایه هم بسته می‌شود؟» — نبودِ پروفایل یعنی `true`. */
    public function transCommissionFlagForSupplier(?int $supplierUserId): bool
    {
        if ($supplierUserId === null) {
            return true;
        }

        return (bool) (SupplierProfile::where(SupplierProfile::USER_ID, $supplierUserId)
            ->value(SupplierProfile::TRANS_COMMISSION) ?? true);
    }

    /**
     * مالیاتِ متعلق به کرایه — `0` وقتی قراردادِ تأمین‌کننده `−VAT` است.
     *
     * ⚠️ **چرا این وجود دارد.** فرمولِ POO (وقتی `+VAT`) مالیات را روی
     * `Trans + OP×W` می‌بندد، ولی `final_amount` که به خریدار نشان داده می‌شود یک
     * جمعِ حسابداریِ ساده بود که مالیات را فقط روی کالا حساب می‌کرد و کرایه را
     * **بدونِ مالیات** اضافه می‌کرد. تا وقتی هیچ ردیفِ `shipping_rates` وارد نشده
     * بود کرایه صفر می‌ماند و این اختلاف دیده نمی‌شد.
     *
     * ولی نقطه‌ی سربه‌سر پایین است: با `%OW = ۰٫۴٪` و مالیاتِ ۱۰٪، به‌محضِ اینکه
     * کرایه از حدود **۴٫۶٪ ارزشِ کالا** بگذرد، `POO` از `final_amount` جلو می‌زند —
     * یعنی از کیف‌پولِ خریدار **بیشتر** از عددی که دیده کسر می‌شود. کرایه‌ی میلگرد
     * معمولاً از این نسبت بیشتر است، پس این یک حالتِ نظری نبود.
     *
     * با احتسابِ مالیاتِ کرایه، هر چهار حالتِ فرمول به `final_amount ≥ POO`
     * برمی‌گردند و تنها اختلافِ باقی‌مانده همان تخفیفِ `%OW` است — یک اختلافِ
     * توضیح‌دادنی، همیشه به سودِ خریدار.
     *
     * @see PooVersusDisplayedAmountTest
     */
    public function vatOnTrans(float $trans, float $vatPercent, bool $transVat): float
    {
        if (! $transVat || $trans <= 0 || $vatPercent <= 0) {
            return 0.0;
        }

        return self::rial(Money::mul($trans, Money::div($vatPercent, '100')));
    }

    /**
     * سقفِ تأمینِ تخفیفِ کوپن از سهمِ شرکت و سهمِ الیت‌یار در **همین** سفارش —
     * `docs/specs/COUPON_FUNDING_2026-09-15.md` §۳.۳.
     *
     * همان ورودی‌هایی که `ProcessPreScalePaymentAction` بعداً واقعاً به
     * `calculatePOO/PTS` می‌دهد؛ استخر `POO − PTS` است، سهمِ ناخالصِ الیت‌یار
     * `pool × CO/(CO+SSG)` فقط وقتی الیت‌یارِ کمیسیون‌گیر روی سفارش هست (همان
     * شرطِ `WeighbridgeVatRouter::routeEliteYAR`)، سهمِ شرکت باقیِ استخر.
     *
     * سقف‌ها **خالص از مالیات**اند: مالیاتِ سهم پولِ شرکت/الیت‌یار نیست که از آن
     * تخفیف داده شود. سقفِ عمرِ الیت‌یار (خوابیده) لحاظ نمی‌شود — اگر فعال شود سهمِ
     * واقعی از این سقف کمتر است، نه بیشتر.
     *
     * @return array{poo: float, pts: float, company: float, elite_yar: float} ریالِ صحیح
     */
    public function couponFundingCaps(
        float $trans,
        float $op,
        float $weight,
        ?string $badgeCode,
        float $vatPercent,
        bool $transCommission,
        bool $transVat,
        bool $hasCommissionedEliteYAR,
        ?float $tcPercentage = null,
        ?float $supplierOp = null,
        ?float $owAppliedPercent = null,
    ): array {
        // %TC قراردادیِ تأمین‌کننده (پرسشِ ۴)، PTS از قیمتِ خودِ تأمین‌کننده (پرسشِ ۶) و
        // %OW فقط با الیت‌یار (پرسشِ ۷) — همان ورودی‌هایی که ثبتِ سفارش اسنپ‌شات می‌کند.
        $rates = $this->getCommissionRatesForTc($badgeCode, $tcPercentage ?? self::tcPercentage());
        $owApplied = $owAppliedPercent ?? $rates['ow_final_rate'];

        $poo = $this->calculatePOO($trans, $op, $weight, $owApplied, $vatPercent, $transCommission, $transVat);
        $pts = $this->calculatePTS(
            $trans, $supplierOp ?? $op, $weight,
            $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'],
            $vatPercent, $transCommission, $transVat
        );

        $pool = max(0.0, $poo - $pts);
        $coPlusSsg = $rates['co_final_rate'] + $rates['ssg_final_rate'];

        $eliteYARGross = $hasCommissionedEliteYAR && $coPlusSsg > 0
            ? self::rial(Money::mul($pool, Money::div($rates['co_final_rate'], $coPlusSsg)))
            : 0.0;
        $companyGross = max(0.0, $pool - $eliteYARGross);

        return [
            'poo' => $poo,
            'pts' => $pts,
            'company' => $this->extractVatInRials($companyGross, $vatPercent)['net'],
            'elite_yar' => $this->extractVatInRials($eliteYARGross, $vatPercent)['net'],
        ];
    }

    /**
     * Split a VAT-inclusive gross amount into its net and VAT parts.
     *
     * Uses vat = gross × VAT / (100 + VAT) — the division acts on large operands,
     * so no precision is lost to a sub-unit multiplier (e.g. 1,100,000,000 with
     * 10% VAT → vat = 100,000,000 exactly).
     *
     * @return array{net: float, vat: float}
     */
    public function extractVat(float $gross, float $vatPercent): array
    {
        $vat = Money::toFloat(
            Money::div(Money::mul($gross, $vatPercent), Money::add('100', $vatPercent))
        );

        // net از vatِ *گردشده* مشتق می‌شود تا `net + vat === gross` دقیقاً برقرار باشد.
        // پیش از این هر دو مستقل گرد می‌شدند و مجموعشان می‌توانست تا ۰٫۰۱ ریال از
        // gross فاصله بگیرد — و چون WeighbridgeVatRouter کیف پول سازه را به اندازه‌ی
        // gross بدهکار و WEY/WVAT را به اندازه‌ی net و vat بستانکار می‌کند، همان
        // اختلاف به‌صورت یک عدم‌توازنِ زیرریالی در لجر دوطرفه می‌نشست.
        $net = Money::toFloat(Money::sub($gross, $vat));

        return [
            'net' => $net,
            'vat' => $vat,
        ];
    }

    /**
     * همان تقسیم، ولی در **دقتی که واقعاً ذخیره می‌شود** — ریالِ صحیح.
     *
     * `extractVat()` تضمین می‌کند `net + vat === gross` با دو رقم اعشار. ولی
     * `wallets.balance`, `wallets.balance_hold` و `wallet_transactions.amount` هر
     * سه `decimal(20,0)`اند، پس هر لِگ **جداگانه** به ریالِ صحیح گرد می‌شود و آن
     * تضمین سرِ نوشتن از بین می‌رود: هرجا کسرِ اعشاریِ net و vat دو طرفِ ۰٫۵
     * بیفتند، `round(net) + round(vat)` یک ریال از `round(gross)` فاصله می‌گیرد.
     *
     * این فقط در `WeighbridgeVatRouter::routeEliteYAR()` اهمیت دارد — تنها جایی
     * که **یک** بدهکار با **دو** بستانکارِ مستقل جفت می‌شود. بقیه‌ی مسیرها یک
     * بدهکار و یک بستانکار با عددِ یکسان دارند، پس همیشه تراز می‌مانند.
     *
     * پیامدش آرام ولی خورنده بود: `LedgerReconciler::unbalancedOrders()` روی
     * سهمِ بزرگی از سفارش‌های دارای الیت‌یار «پول خلق یا نابود شده» گزارش می‌داد،
     * و ابزارِ تشخیصی که قرار بود مغایرتِ واقعی را پیدا کند به نویز تبدیل می‌شد.
     *
     * راهکار همان قاعده‌ای است که پروژه از قبل جای دیگر به‌کار می‌برد
     * (`ShippingCostCalculator`, `InventoryService::updateStockAfterWeighbridge
     * ForOrder`): اول کل را به دقتِ مقصد گرد کن، یک سهم را حساب کن، و **سهمِ
     * آخر را از تفاضل بگیر** تا جمع به‌طور ساختاری دقیق دربیاید — نه با امید.
     *
     * @return array{gross: float, net: float, vat: float} هر سه، ریالِ صحیح
     */
    public function extractVatInRials(float $gross, float $vatPercent): array
    {
        $grossRial = round($gross);

        if ($grossRial <= 0.0) {
            return ['gross' => 0.0, 'net' => 0.0, 'vat' => 0.0];
        }

        // مالیات از همان عددی مشتق می‌شود که واقعاً بدهکار می‌شود، نه از ورودیِ اعشاری.
        $vatRial = round($this->extractVat($grossRial, $vatPercent)['vat']);

        // بازمانده — نه یک گردکردنِ مستقل. `net + vat === gross` این‌طور اصل است.
        $netRial = $grossRial - $vatRial;

        return ['gross' => $grossRial, 'net' => $netRial, 'vat' => $vatRial];
    }
}
