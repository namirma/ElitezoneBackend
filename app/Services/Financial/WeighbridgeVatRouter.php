<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\DataTransferObjects\Order\WeighbridgeReconcileResult;
use App\Models\Order;
use App\Models\User;

/**
 * Executes all three VAT-routing legs that follow every weighbridge result.
 *
 * Three legs — eliteYAR, supplier, saze — must run in every weighbridge outcome
 * (equal or deficit), so this service is called unconditionally by the orchestrator
 * after the deficit leg (if any) has been resolved.
 *
 * Must be called inside an active DB::transaction with a locked Order.
 * Returns the actual eliteYARGross so the orchestrator can snapshot it on the order.
 */
class WeighbridgeVatRouter
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine,
        protected SettlementCalculatorService $calculator,
    ) {}

    public function route(Order $order, WeighbridgeReconcileResult $result): float
    {
        // ?-> و گاردِ صریح: orchestrator در همان نقطه null-safe بود ولی اینجا نبود، پس
        // سفارشِ بی‌آیتم به‌جای یک خطای معنادار، fatal داخلِ تراکنشِ مالی می‌داد.
        $supplierId = (int) ($order->items->first()?->supplier_user_id ?? 0);

        if ($supplierId <= 0) {
            throw new \RuntimeException(
                "WeighbridgeVatRouter: order {$order->id} has no item with a supplier_user_id — cannot route settlement legs."
            );
        }

        $eliteYARGross = $this->routeEliteYAR($order, $result);
        $this->routeSupplierVat($order, $result, $supplierId);
        $this->routeSazeVat($order, $result, $eliteYARGross);

        return $eliteYARGross;
    }

    /**
     * Routes the eliteYAR's net commission and its VAT into their respective holds.
     * Returns the gross amount allocated (after lifetime cap), or 0.0 if no eliteYAR.
     */
    private function routeEliteYAR(
        Order $order,
        WeighbridgeReconcileResult $result,
    ): float {
        $eliteYARUserId = (int) ($order->{Order::ELITE_YAR_USER_ID} ?? 0);
        $buyerId = (int) $order->placer_user_id;

        if (! $eliteYARUserId || $eliteYARUserId === $buyerId) {
            return 0.0;
        }

        // PTC = %CO slice of the commission pool (POOM − PTSM).
        // Derived from the pool instead of re-applying an ad-hoc formula so it inherits
        // the correct ±TC/±VAT handling that calculatePOO/PTS already applied.
        $coRate = $result->rates['co_final_rate'];
        $ssgRate = $result->rates['ssg_final_rate'];
        $pool = $result->newPOO - $result->newPTS;

        $candidateGross = ($coRate + $ssgRate) > 0
            ? $pool * ($coRate / ($coRate + $ssgRate))
            : 0.0;

        if ($candidateGross <= 0.0) {
            return 0.0;
        }

        $candidateGross = $this->applyEliteYARLifetimeCap(
            $eliteYARUserId,
            (int) $order->id,
            $candidateGross
        );

        if ($candidateGross <= 0.0) {
            return 0.0;
        }

        // تنها جای سیستم که **یک** بدهکار به **دو** بستانکار تقسیم می‌شود، پس
        // تنها جایی که گردکردنِ مستقل می‌تواند ریال خلق یا نابود کند. تقسیم در
        // دقتِ ذخیره‌سازی انجام می‌شود تا `net + vat === gross` ساختاری باشد؛
        // توضیح کامل در `SettlementCalculatorService::extractVatInRials()`.
        ['gross' => $gross, 'net' => $net, 'vat' => $vat] =
            $this->calculator->extractVatInRials($candidateGross, $result->vat);

        if ($gross <= 0.0) {
            return 0.0;
        }

        $this->walletEngine->debitSystem($order, $gross, FinancialCodes::STATUS_AVAILABLE, "تخصیص سهم الیت یار سفارش {$order->order_number}");

        // لِگِ صفر رد می‌شود — موتور مبلغِ نامثبت را رد می‌کند و کلِ باسکول را
        // rollback می‌کرد (نرخ مالیاتِ صفر روی `price_settings` این را ممکن می‌کرد).
        // چون net و vat جمعاً دقیقاً gross‌اند، حذفِ لِگِ صفر تراز را به هم نمی‌زند.
        if ($net > 0.0) {
            $this->walletEngine->creditEliteYAR($order, $net, FinancialCodes::STATUS_HOLD, $eliteYARUserId, 'کمیسیون امانی الیت یار');
        }

        if ($vat > 0.0) {
            $this->walletEngine->creditVat($order, $vat, FinancialCodes::STATUS_HOLD, 'مالیات کمیسیون الیت یار امانی');
        }

        return $gross;
    }

    /**
     * Deducts the supplier's escrow VAT from WALLET_SUPPLIER and holds it in WALLET_VAT.
     * (Doc O — RecordSupplierMoadianInvoiceAction releases this hold later.)
     */
    private function routeSupplierVat(
        Order $order,
        WeighbridgeReconcileResult $result,
        int $supplierId,
    ): void {
        $supplierVat = $this->calculator->extractVat($result->newPTS, $result->vat)['vat'];
        if ($supplierVat <= 0.0) {
            return;
        }

        $this->walletEngine->debitSupplier($order, $supplierVat, FinancialCodes::STATUS_AVAILABLE, $supplierId, "کسر مالیات امانی سفارش {$order->order_number}");
        $this->walletEngine->creditVat($order, $supplierVat, FinancialCodes::STATUS_HOLD, 'مالیات تامین‌کننده امانی');
    }

    /**
     * Deducts the platform's (Saze) escrow VAT from WALLET_SAZE and holds it in WALLET_VAT.
     * sazeGross = commissionPool − eliteYARGross.
     * (Doc P — RecordSystemMoadianInvoiceAction releases this hold later.)
     */
    private function routeSazeVat(
        Order $order,
        WeighbridgeReconcileResult $result,
        float $eliteYARGross,
    ): void {
        $sazeGross = ($result->newPOO - $result->newPTS) - $eliteYARGross;
        $sazeVat = $this->calculator->extractVat($sazeGross, $result->vat)['vat'];
        if ($sazeVat <= 0.0) {
            return;
        }

        $this->walletEngine->debitSystem($order, $sazeVat, FinancialCodes::STATUS_AVAILABLE, 'کسر مالیات بر ارزش افزوده پلتفرم');
        $this->walletEngine->creditVat($order, $sazeVat, FinancialCodes::STATUS_HOLD, 'مالیات گروه سازه امانی');
    }

    /**
     * Cap the eliteYAR payout to their remaining lifetime headroom.
     *
     * Spec ("پروسه خرید"): the total lifetime commission paid to a gold/silver/bronze
     * eliteYAR may not exceed `cap_multiplier × badge-purchase invoice`. We sum the
     * gross already routed to this eliteYAR on prior settled orders and clamp the
     * current allocation to whatever ceiling headroom remains (never below zero).
     *
     * A eliteYAR with no recorded badge-purchase amount (NULL) is uncapped — see
     * eliteYARLifetimeCeiling().
     */
    private function applyEliteYARLifetimeCap(
        int $eliteYARUserId,
        int $currentOrderId,
        float $candidateGross,
    ): float {
        $ceiling = $this->eliteYARLifetimeCeiling($eliteYARUserId);
        if ($ceiling === null) {
            return $candidateGross;
        }

        $alreadyPaid = (float) Order::query()
            ->where(Order::ELITE_YAR_USER_ID, $eliteYARUserId)
            ->whereNotNull(Order::WEIGHING_COMPLETED_AT)
            ->where(Order::ID, '!=', $currentOrderId)
            ->sum(Order::PAYMENT_TO_ELITE_YAR);

        return min($candidateGross, max(0.0, $ceiling - $alreadyPaid));
    }

    /**
     * Returns the lifetime commission ceiling for a eliteYAR, or null when uncapped.
     *
     * ceiling = config('financial.elite_yar_lifetime_cap_multiplier') × the invoice the
     * eliteYAR paid when purchasing their badge (users.badge_purchase_amount). When that
     * amount is NULL or non-positive (e.g. pioneer/normal eliteYARs who never bought a
     * paid badge) there is no cap and we return null so applyEliteYARLifetimeCap() leaves
     * the allocation untouched.
     *
     * قفلِ ردیفِ کاربر اینجا گرفته می‌شود و همین قفل، سریال‌سازیِ سقف را تأمین
     * می‌کند: هر تسویهٔ باسکول در تراکنشِ خودش فقط ردیفِ Order خودش را قفل
     * می‌کرد، پس دو سفارشِ هم‌زمانِ یک الیت‌یار هر دو یک `alreadyPaid` یکسان
     * می‌خواندند و هر دو از سقف عبور می‌کردند. حالا دومی تا commit شدنِ اولی
     * پشتِ این قفل می‌ماند و مجموعِ به‌روز را می‌بیند.
     *
     * برای الیت‌یارِ بدون سقف (badge_purchase_amount خالی) قفل بی‌اثر است چون
     * هیچ سقفی برای رقابت وجود ندارد.
     */
    private function eliteYARLifetimeCeiling(int $eliteYARUserId): ?float
    {
        $badgePurchaseAmount = User::query()
            ->whereKey($eliteYARUserId)
            ->lockForUpdate()
            ->value(User::BADGE_PURCHASE_AMOUNT);

        if ($badgePurchaseAmount === null || (float) $badgePurchaseAmount <= 0.0) {
            return null;
        }

        $multiplier = (float) config('financial.elite_yar_lifetime_cap_multiplier', 2.0);

        return (float) $badgePurchaseAmount * $multiplier;
    }
}
