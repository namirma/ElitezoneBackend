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
 * Three legs — eliteYar, supplier, saze — must run in every weighbridge outcome
 * (equal or deficit), so this service is called unconditionally by the orchestrator
 * after the deficit leg (if any) has been resolved.
 *
 * Must be called inside an active DB::transaction with a locked Order.
 * Returns the actual eliteYarGross so the orchestrator can snapshot it on the order.
 */
class WeighbridgeVatRouter
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine,
        protected SettlementCalculatorService $calculator,
    ) {}

    public function route(Order $order, WeighbridgeReconcileResult $result): float
    {
        $supplierId = (int) $order->items->first()->supplier_user_id;

        $eliteYarGross = $this->routeEliteYar($order, $result);
        $this->routeSupplierVat($order, $result, $supplierId);
        $this->routeSazeVat($order, $result, $eliteYarGross);

        return $eliteYarGross;
    }

    /**
     * Routes the eliteYar's net commission and its VAT into their respective holds.
     * Returns the gross amount allocated (after lifetime cap), or 0.0 if no eliteYar.
     */
    private function routeEliteYar(
        Order $order,
        WeighbridgeReconcileResult $result,
    ): float {
        $eliteYarUserId = (int) ($order->{Order::ELITE_YAR_USER_ID} ?? 0);
        $buyerId = (int) $order->placer_user_id;

        if (! $eliteYarUserId || $eliteYarUserId === $buyerId) {
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

        $candidateGross = $this->applyEliteYarLifetimeCap(
            $eliteYarUserId,
            (int) $order->id,
            $candidateGross
        );

        if ($candidateGross <= 0.0) {
            return 0.0;
        }

        ['net' => $net, 'vat' => $vat] = $this->calculator->extractVat($candidateGross, $result->vat);

        $this->walletEngine->debitSystem($order, $candidateGross, FinancialCodes::STATUS_AVAILABLE, "تخصیص سهم الیت یار سفارش {$order->order_number}");
        $this->walletEngine->creditEliteYar($order, $net, FinancialCodes::STATUS_HOLD, $eliteYarUserId, 'کمیسیون امانی الیت یار');
        $this->walletEngine->creditVat($order, $vat, FinancialCodes::STATUS_HOLD, 'مالیات کمیسیون الیت یار امانی');

        return $candidateGross;
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
     * sazeGross = commissionPool − eliteYarGross.
     * (Doc P — RecordSystemMoadianInvoiceAction releases this hold later.)
     */
    private function routeSazeVat(
        Order $order,
        WeighbridgeReconcileResult $result,
        float $eliteYarGross,
    ): void {
        $sazeGross = ($result->newPOO - $result->newPTS) - $eliteYarGross;
        $sazeVat = $this->calculator->extractVat($sazeGross, $result->vat)['vat'];
        if ($sazeVat <= 0.0) {
            return;
        }

        $this->walletEngine->debitSystem($order, $sazeVat, FinancialCodes::STATUS_AVAILABLE, 'کسر مالیات بر ارزش افزوده پلتفرم');
        $this->walletEngine->creditVat($order, $sazeVat, FinancialCodes::STATUS_HOLD, 'مالیات گروه سازه امانی');
    }

    /**
     * Cap the eliteYar payout to their remaining lifetime headroom.
     *
     * Spec ("پروسه خرید"): the total lifetime commission paid to a gold/silver/bronze
     * eliteYar may not exceed `cap_multiplier × badge-purchase invoice`. We sum the
     * gross already routed to this eliteYar on prior settled orders and clamp the
     * current allocation to whatever ceiling headroom remains (never below zero).
     *
     * A eliteYar with no recorded badge-purchase amount (NULL) is uncapped — see
     * eliteYarLifetimeCeiling().
     */
    private function applyEliteYarLifetimeCap(
        int $eliteYarUserId,
        int $currentOrderId,
        float $candidateGross,
    ): float {
        $ceiling = $this->eliteYarLifetimeCeiling($eliteYarUserId);
        if ($ceiling === null) {
            return $candidateGross;
        }

        $alreadyPaid = (float) Order::query()
            ->where(Order::ELITE_YAR_USER_ID, $eliteYarUserId)
            ->whereNotNull(Order::WEIGHING_COMPLETED_AT)
            ->where(Order::ID, '!=', $currentOrderId)
            ->sum(Order::PAYMENT_TO_ELITE_YAR);

        return min($candidateGross, max(0.0, $ceiling - $alreadyPaid));
    }

    /**
     * Returns the lifetime commission ceiling for a eliteYar, or null when uncapped.
     *
     * ceiling = config('financial.consultant_lifetime_cap_multiplier') × the invoice the
     * eliteYar paid when purchasing their badge (users.badge_purchase_amount). When that
     * amount is NULL or non-positive (e.g. pioneer/normal eliteYars who never bought a
     * paid badge) there is no cap and we return null so applyEliteYarLifetimeCap() leaves
     * the allocation untouched.
     */
    private function eliteYarLifetimeCeiling(int $eliteYarUserId): ?float
    {
        $badgePurchaseAmount = User::query()
            ->whereKey($eliteYarUserId)
            ->value(User::BADGE_PURCHASE_AMOUNT);

        if ($badgePurchaseAmount === null || (float) $badgePurchaseAmount <= 0.0) {
            return null;
        }

        $multiplier = (float) config('financial.consultant_lifetime_cap_multiplier', 2.0);

        return (float) $badgePurchaseAmount * $multiplier;
    }
}
