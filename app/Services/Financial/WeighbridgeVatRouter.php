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
 * Three legs — consultant, supplier, saze — must run in every weighbridge outcome
 * (equal or deficit), so this service is called unconditionally by the orchestrator
 * after the deficit leg (if any) has been resolved.
 *
 * Must be called inside an active DB::transaction with a locked Order.
 * Returns the actual consultantGross so the orchestrator can snapshot it on the order.
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

        $consultantGross = $this->routeConsultant($order, $result);
        $this->routeSupplierVat($order, $result, $supplierId);
        $this->routeSazeVat($order, $result, $consultantGross);

        return $consultantGross;
    }

    /**
     * Routes the consultant's net commission and its VAT into their respective holds.
     * Returns the gross amount allocated (after lifetime cap), or 0.0 if no consultant.
     */
    private function routeConsultant(
        Order $order,
        WeighbridgeReconcileResult $result,
    ): float {
        $consultantUserId = (int) ($order->{Order::CONSULTANT_USER_ID} ?? 0);
        $buyerId = (int) $order->placer_user_id;

        if (! $consultantUserId || $consultantUserId === $buyerId) {
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

        $candidateGross = $this->applyConsultantLifetimeCap(
            $consultantUserId,
            (int) $order->id,
            $candidateGross
        );

        if ($candidateGross <= 0.0) {
            return 0.0;
        }

        ['net' => $net, 'vat' => $vat] = $this->calculator->extractVat($candidateGross, $result->vat);

        $this->walletEngine->debitSystem($order, $candidateGross, FinancialCodes::STATUS_AVAILABLE, "تخصیص سهم مشاور فنی سفارش {$order->order_number}");
        $this->walletEngine->creditConsultant($order, $net, FinancialCodes::STATUS_HOLD, $consultantUserId, 'کمیسیون امانی مشاور فنی');
        $this->walletEngine->creditVat($order, $vat, FinancialCodes::STATUS_HOLD, 'مالیات کمیسیون مشاور فنی امانی');

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
     * sazeGross = commissionPool − consultantGross.
     * (Doc P — RecordSystemMoadianInvoiceAction releases this hold later.)
     */
    private function routeSazeVat(
        Order $order,
        WeighbridgeReconcileResult $result,
        float $consultantGross,
    ): void {
        $sazeGross = ($result->newPOO - $result->newPTS) - $consultantGross;
        $sazeVat = $this->calculator->extractVat($sazeGross, $result->vat)['vat'];
        if ($sazeVat <= 0.0) {
            return;
        }

        $this->walletEngine->debitSystem($order, $sazeVat, FinancialCodes::STATUS_AVAILABLE, 'کسر مالیات بر ارزش افزوده پلتفرم');
        $this->walletEngine->creditVat($order, $sazeVat, FinancialCodes::STATUS_HOLD, 'مالیات گروه سازه امانی');
    }

    /**
     * Cap the consultant payout to their remaining lifetime headroom.
     *
     * Spec ("پروسه خرید"): the total lifetime commission paid to a gold/silver/bronze
     * consultant may not exceed `cap_multiplier × badge-purchase invoice`. We sum the
     * gross already routed to this consultant on prior settled orders and clamp the
     * current allocation to whatever ceiling headroom remains (never below zero).
     *
     * A consultant with no recorded badge-purchase amount (NULL) is uncapped — see
     * consultantLifetimeCeiling().
     */
    private function applyConsultantLifetimeCap(
        int $consultantUserId,
        int $currentOrderId,
        float $candidateGross,
    ): float {
        $ceiling = $this->consultantLifetimeCeiling($consultantUserId);
        if ($ceiling === null) {
            return $candidateGross;
        }

        $alreadyPaid = (float) Order::query()
            ->where(Order::CONSULTANT_USER_ID, $consultantUserId)
            ->whereNotNull(Order::WEIGHING_COMPLETED_AT)
            ->where(Order::ID, '!=', $currentOrderId)
            ->sum(Order::PAYMENT_TO_CONSULTANT);

        return min($candidateGross, max(0.0, $ceiling - $alreadyPaid));
    }

    /**
     * Returns the lifetime commission ceiling for a consultant, or null when uncapped.
     *
     * ceiling = config('financial.consultant_lifetime_cap_multiplier') × the invoice the
     * consultant paid when purchasing their badge (users.badge_purchase_amount). When that
     * amount is NULL or non-positive (e.g. pioneer/normal consultants who never bought a
     * paid badge) there is no cap and we return null so applyConsultantLifetimeCap() leaves
     * the allocation untouched.
     */
    private function consultantLifetimeCeiling(int $consultantUserId): ?float
    {
        $badgePurchaseAmount = User::query()
            ->whereKey($consultantUserId)
            ->value(User::BADGE_PURCHASE_AMOUNT);

        if ($badgePurchaseAmount === null || (float) $badgePurchaseAmount <= 0.0) {
            return null;
        }

        $multiplier = (float) config('financial.consultant_lifetime_cap_multiplier', 2.0);

        return (float) $badgePurchaseAmount * $multiplier;
    }
}
