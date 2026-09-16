<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Order;

/**
 * Immutable snapshot of POOM/PTSM recalculation after the scale reading.
 * Pure data — no DB access, no side effects. Produced by WeighbridgeReconciler
 * and consumed by the orchestrator, WeighbridgeVatRouter, and SupplierDebtHandler.
 */
final class WeighbridgeReconcileResult
{
    public function __construct(
        /**
         * وزنِ مبنای صورت‌حساب = وزنِ واقعیِ باسکول.
         *
         * تا نسخه‌ی ۱ سند اینجا `min(actual, WBS)` بود («فاکتور نهایی هرگز از
         * فاکتور اولیه بیشتر نیست»). نسخه‌ی ۲ بند ۸.۲ آن clamp را برداشت:
         * اضافه‌بار درون تلورانس واقعاً صورت‌حساب می‌شود، و فراتر از تلورانس
         * سفارش را متوقف می‌کند (`awaiting_weight_correction`) نه اینکه
         * بی‌صدا رایگان تحویل شود.
         */
        public readonly float $billingWeight,
        /** POOM: POO recomputed with billingWeight (buyer's revised amount). */
        public readonly float $newPOO,
        /** PTSM: PTS recomputed with billingWeight (supplier's revised amount). */
        public readonly float $newPTS,
        /** POOM − POO_original. منفی = کم‌تحویلی، مثبت = اضافه‌بار (نسخه‌ی ۲). */
        public readonly float $diffPOO,
        /** PTSM − PTS_original. Sign mirrors diffPOO. */
        public readonly float $diffPTS,
        /** Badge-based commission rates: ow_final_rate, co_final_rate, ssg_final_rate, etc. */
        public readonly array $rates,
        /** VAT rate (%) from the order snapshot (e.g. 10.0 for 10%). */
        public readonly float $vat,
        /** درصد اضافه‌بار نسبت به `weight_before_scale`؛ صفر یا منفی = بدون اضافه‌بار. */
        public readonly float $overweightPercent = 0.0,
        /** کرایه‌ی بازمحاسبه‌شده با وزن واقعی (بند ۸.۲ گام ۲). */
        public readonly float $newShippingCost = 0.0,
        /** تخفیفِ کوپن پس از بازمحاسبه با POOM (بند ۸.۲ گام ۴). */
        public readonly float $newCouponDiscount = 0.0,
        /**
         * ‏`newCouponDiscount − تخفیفی که هنگام پرداخت واقعاً به خریدار داده شد`.
         * مثبت = تخفیفِ تکمیلی به خریدار، منفی = خریدار مازاد را برمی‌گرداند.
         * کوپنِ مبلغ‌ثابت همیشه صفر.
         */
        public readonly float $couponDiscountDelta = 0.0,
    ) {}
}
