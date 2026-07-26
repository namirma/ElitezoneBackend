<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\DataTransferObjects\Order\WeighbridgeReconcileResult;
use App\Models\Order;

/**
 * Pure recalculation service for post-weighbridge settlement.
 *
 * No DB writes, no wallet ops, no side effects. Given an Order (with loaded
 * items + eliteYAR.badge relations) and the scale's actual weight, produces
 * a WeighbridgeReconcileResult that every downstream step can read from.
 */
class WeighbridgeReconciler
{
    public function __construct(
        protected SettlementCalculatorService $calculator,
    ) {}

    public function reconcile(Order $order, float $actualWeight): WeighbridgeReconcileResult
    {
        $wbs = (float) $order->weight_before_scale;

        // Business invariant (spec "پروسه خرید"): the final invoice is always ≤ the
        // initial invoice. Over-delivery (actualWeight > WBS) is recorded physically
        // but never charged — there is deliberately no "surplus" payment path.
        $billingWeight = min($actualWeight, $wbs);

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

        $trans = (float) $order->shipping_cost;
        $vat = (float) ($order->vat_rate ?? config('financial.default_vat_rate'));
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
        );
    }
}
