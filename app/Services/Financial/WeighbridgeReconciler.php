<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\DataTransferObjects\Order\WeighbridgeReconcileResult;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Marketing\CouponService;
use App\Services\Shipping\ShippingCostCalculator;

/**
 * Recalculation service for post-weighbridge settlement.
 *
 * **No writes, no wallet ops, no side effects** — it only reads. (It does read:
 * shipment rows and shipping rates for the freight recompute, and the coupon
 * row; the invariant that matters is that nothing here mutates state.) Given an
 * Order and the scale's actual weight, produces a WeighbridgeReconcileResult
 * that every downstream step reads from.
 */
class WeighbridgeReconciler
{
    public function __construct(
        protected SettlementCalculatorService $calculator,
        protected ShippingCostCalculator $shippingCalculator,
        protected CouponService $couponService,
    ) {}

    public function reconcile(Order $order, float $actualWeight): WeighbridgeReconcileResult
    {
        $wbs = (float) $order->weight_before_scale;

        // ⚠️ clamp عمداً حذف شده — نسخه‌ی ۲ سند، بند ۸.۲.
        //
        // تا پیش از این `billingWeight = min(actualWeight, WBS)` بود، با این
        // استدلال که «فاکتور نهایی هرگز از فاکتور اولیه بیشتر نمی‌شود». نتیجه‌ی
        // عملی‌اش این بود که هر کیلوگرم اضافه‌بار **رایگان** تحویل می‌شد: وزن
        // فیزیکی و موجودی درست ثبت می‌شدند ولی هیچ‌کس بابتش پول نمی‌داد، و چون
        // `diffPOO` هیچ‌وقت مثبت نمی‌شد، هیچ گزارشی هم آن را نشان نمی‌داد.
        //
        // حالا وزن واقعی مبناست و دو گارد جای clamp را می‌گیرند:
        //   • رضایتِ دوطرفه — **هر** اختلافِ وزن سفارش را متوقف می‌کند
        //     (`awaiting_weight_correction`) تا خریدار و تأمین‌کننده هر دو
        //     تأیید کنند؛ هیچ لِگ مالی‌ای پیش از آن اجرا نمی‌گردد. (تلورانسِ
        //     درصدیِ قدیم در تصمیم مدیریت ۲۰۲۶-۰۹-۰۹ حذف شد.)
        //   • سقف اعتبار — اگر خریدار موجودی نداشته باشد، مابقی بدهی می‌شود.
        // هر دو در `ProcessWeighbridgeResultAction` اعمال می‌شوند.
        $billingWeight = $actualWeight;

        $overweightPercent = $wbs > 0
            ? (($actualWeight - $wbs) / $wbs) * 100
            : 0.0;

        $badgeCode = $order->eliteYAR?->badge?->code ?? 'pioneer';

        // %TC از اسنپ‌شاتِ سفارش خوانده می‌شود، نه از تنظیمِ زندهٔ PriceSetting.
        // tc_percentage یک مقدارِ سراسریِ قابل‌تغییر است؛ خواندنِ زندهٔ آن یعنی یک
        // ویرایشِ تنظیمات بین پرداخت و باسکول، POOM را با وزنِ کاملاً یکسان از POO
        // دور می‌کرد و شاخهٔ «کسری» را فعال می‌کرد — یعنی عودتِ واقعی به خریدار و
        // ثبتِ بدهیِ واقعی برای تأمین‌کننده، بدون آنکه هیچ کیلوگرمی جابه‌جا شده باشد.
        // سفارش‌های قدیمی که این ستون را ندارند به مقدارِ زنده برمی‌گردند (سازگاری).
        $snapshotTc = (float) ($order->{Order::COMMISSION_RATE} ?? 0);
        $rates = $snapshotTc > 0
            ? $this->calculator->getCommissionRatesForTc($badgeCode, $snapshotTc)
            : $this->calculator->getCommissionRates($badgeCode);

        // کرایه با وزن **واقعیِ هر محموله** بازمحاسبه می‌شود (بند ۸.۲ گام ۲).
        // per-shipment و نه per-order، چون نرخ به مبدأ و مقصد وابسته است و دو
        // لِگ می‌توانند مبنای صورت‌حساب متفاوت داشته باشند (وزنی/تعدادی).
        $trans = $this->recalculateShipping($order);
        // منبع واحد؛ `??` روی ستونِ NOT NULL DEFAULT 0.00 هرگز فالبک نمی‌گیرد و
        // بازمحاسبهٔ باسکول با مالیات صفر انجام می‌شد.
        $vat = $this->calculator->resolveVatRate($order);
        $op = ((float) $order->official_price > 0)
            ? (float) $order->official_price
            : (float) ($order->items->first()?->unit_price ?? 0);

        // قیمت صفر یعنی دادهٔ خراب. بدونِ این گارد، POOM روی op=0 تقریباً صفر
        // می‌شد و diffPOO به‌شدت منفی → شاخهٔ «کسری» → عودتِ کلِ مبلغ به خریدار و
        // ثبتِ بدهی برای تأمین‌کننده، فقط به‌خاطر یک ردیفِ ناقص.
        if ($op <= 0) {
            throw new \RuntimeException(__('orders.invalid_official_price'));
        }

        // Formula-variant flags are snapshotted onto the order at payment time
        // so they are immutable for the life of the order.
        $transCommission = (bool) ($order->{Order::TRANS_COMMISSION} ?? true);
        $transVat = (bool) ($order->{Order::TRANS_VAT} ?? true);

        $newPOO = $this->calculator->calculatePOO(
            $trans, $op, $billingWeight,
            $rates['ow_final_rate'], $vat,
            $transCommission, $transVat
        );

        $newPTS = $this->calculator->calculatePTS(
            $trans, $op, $billingWeight,
            $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], $vat,
            $transCommission, $transVat
        );

        return new WeighbridgeReconcileResult(
            billingWeight: $billingWeight,
            newPOO: $newPOO,
            newPTS: $newPTS,
            diffPOO: $newPOO - (float) $order->payment_of_owner,
            diffPTS: $newPTS - (float) $order->payment_to_supplier,
            rates: $rates,
            vat: $vat,
            overweightPercent: $overweightPercent,
            newShippingCost: $trans,
            newCouponDiscount: $this->recalculateCoupon($order, $newPOO),
        );
    }

    /**
     * کرایه‌ی کل با وزنِ واقعیِ ثبت‌شده روی محموله‌ها (بند ۸.۲ گام ۲).
     *
     * اگر هیچ محموله‌ای وزنِ واقعی ندارد — مسیر قدیمیِ باسکولِ ادمین که وزن کل
     * را مستقیم می‌دهد و محموله‌ای ثبت نشده — کرایه‌ی اولیه دست‌نخورده
     * برمی‌گردد. عوض‌کردنش در آن حالت یعنی حدس‌زدن، و حدس روی مسیر مالی
     * بدتر از دست‌نزدن است.
     */
    private function recalculateShipping(Order $order): float
    {
        $shipments = $order->shipments()
            ->whereNotNull(Shipment::ACTUAL_WEIGHT)
            ->get();

        if ($shipments->isEmpty()) {
            return (float) $order->shipping_cost;
        }

        // هر محموله یک «خط» می‌شود؛ چون کلیدِ گروه‌بندیِ ماشین‌حساب همان
        // (مبدأ، مقصد) است، هر خط دقیقاً به یک لِگ نگاشت می‌شود.
        $lines = [];
        foreach ($shipments as $shipment) {
            /** @var Shipment $shipment */
            $lines[(string) $shipment->id] = [
                'factory_id' => $shipment->{Shipment::FACTORY_ID},
                'factory_location_id' => $shipment->{Shipment::FACTORY_LOCATION_ID},
                'address_id' => $shipment->{Shipment::ADDRESS_ID},
                'destination_province_id' => $shipment->{Shipment::DESTINATION_PROVINCE_ID},
                'destination_city_id' => $shipment->{Shipment::DESTINATION_CITY_ID},
                'weight' => (float) $shipment->{Shipment::ACTUAL_WEIGHT},
                // مبنای تعدادی با وزن عوض نمی‌شود — تعدادِ صورت‌حساب‌شده همان است.
                'count' => (float) ($shipment->{Shipment::BILLED_COUNT} ?? 0),
            ];
        }

        return $this->shippingCalculator->quote($lines)->total;
    }

    /**
     * کوپن درصدی با POOM بازمحاسبه می‌شود، مبلغ ثابت دست نمی‌خورد (بند ۸.۲ گام ۴).
     *
     * دلیلش این است که تخفیفِ درصدی یک نسبت از مبلغ است، پس وقتی مبلغ با وزن
     * واقعی عوض می‌شود، تخفیف هم باید عوض شود؛ وگرنه اضافه‌بار با تخفیفِ سفارشِ
     * کوچک‌ترِ اولیه حساب می‌شد.
     */
    private function recalculateCoupon(Order $order, float $newPOO): float
    {
        $couponId = $order->{Order::COUPON_ID};
        $current = (float) ($order->{Order::COUPON_DISCOUNT_AMOUNT} ?? 0);

        if (! $couponId || $current <= 0) {
            return 0.0;
        }

        $coupon = Coupon::find($couponId);

        if (! $coupon || $coupon->{Coupon::TYPE} !== Coupon::TYPE_PERCENT) {
            return $current;
        }

        return $this->couponService->calculateDiscount($coupon, $newPOO);
    }
}
