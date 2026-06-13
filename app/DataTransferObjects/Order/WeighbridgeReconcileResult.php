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
        /** Weight used for billing — min(actualWeight, WBS). Over-delivery is absorbed by stock, never charged. */
        public readonly float $billingWeight,
        /** POOM: POO recomputed with billingWeight (buyer's revised amount). */
        public readonly float $newPOO,
        /** PTSM: PTS recomputed with billingWeight (supplier's revised amount). */
        public readonly float $newPTS,
        /** POOM − POO_original. Negative means under-delivery; positive is impossible by spec (billingWeight ≤ WBS). */
        public readonly float $diffPOO,
        /** PTSM − PTS_original. Sign mirrors diffPOO. */
        public readonly float $diffPTS,
        /** Badge-based commission rates: ow_final_rate, co_final_rate, ssg_final_rate, etc. */
        public readonly array $rates,
        /** VAT rate (%) from the order snapshot (e.g. 10.0 for 10%). */
        public readonly float $vat,
    ) {}
}
