<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\DataTransferObjects\Order\WeighbridgeReconcileResult;
use App\Models\Order;

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
        $systemUserId = (int) config('financial.system_user_id', 1);
        $vatUserId = (int) config('financial.vat_user_id', $systemUserId);
        $supplierId = (int) $order->items->first()->supplier_user_id;

        $consultantGross = $this->routeConsultant($order, $result, $systemUserId, $vatUserId);
        $this->routeSupplierVat($order, $result, $supplierId, $vatUserId);
        $this->routeSazeVat($order, $result, $consultantGross, $systemUserId, $vatUserId);

        return $consultantGross;
    }

    /**
     * Routes the consultant's net commission and its VAT into their respective holds.
     * Returns the gross amount allocated (after lifetime cap), or 0.0 if no consultant.
     */
    private function routeConsultant(
        Order $order,
        WeighbridgeReconcileResult $result,
        int $systemUserId,
        int $vatUserId,
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

        $this->walletEngine->process(
            '-', $candidateGross, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE,
            $order, "تخصیص سهم مشاور فنی سفارش {$order->order_number}", $systemUserId
        );
        $this->walletEngine->process(
            '+', $net, FinancialCodes::WALLET_CONSULTANT, FinancialCodes::STATUS_HOLD,
            $order, 'کمیسیون امانی مشاور فنی', $consultantUserId
        );
        $this->walletEngine->process(
            '+', $vat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD,
            $order, 'مالیات کمیسیون مشاور فنی امانی', $vatUserId
        );

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
        int $vatUserId,
    ): void {
        $supplierVat = $this->calculator->extractVat($result->newPTS, $result->vat)['vat'];
        if ($supplierVat <= 0.0) {
            return;
        }

        $this->walletEngine->process(
            '-', $supplierVat, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE,
            $order, "کسر مالیات امانی سفارش {$order->order_number}", $supplierId
        );
        $this->walletEngine->process(
            '+', $supplierVat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD,
            $order, 'مالیات تامین‌کننده امانی', $vatUserId
        );
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
        int $systemUserId,
        int $vatUserId,
    ): void {
        $sazeGross = ($result->newPOO - $result->newPTS) - $consultantGross;
        $sazeVat = $this->calculator->extractVat($sazeGross, $result->vat)['vat'];
        if ($sazeVat <= 0.0) {
            return;
        }

        $this->walletEngine->process(
            '-', $sazeVat, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE,
            $order, 'کسر مالیات بر ارزش افزوده پلتفرم', $systemUserId
        );
        $this->walletEngine->process(
            '+', $sazeVat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD,
            $order, 'مالیات گروه سازه امانی', $vatUserId
        );
    }

    /**
     * Cap the consultant payout to their remaining lifetime headroom.
     * Spec: total lifetime commission ≤ cap_multiplier × badge-purchase invoice.
     * Returns unchanged candidateGross when no ceiling is modelled yet (current state).
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
     * Returns the lifetime ceiling for a consultant, or null when uncapped.
     *
     * TODO(product): ceiling = cap_multiplier × the consultant's badge-purchase invoice.
     * The per-consultant badge-purchase amount is not modelled yet → returns null (inert).
     */
    private function consultantLifetimeCeiling(int $consultantUserId): ?float
    {
        return null;
    }
}
