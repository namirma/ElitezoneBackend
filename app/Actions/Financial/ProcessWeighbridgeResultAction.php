<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\InventoryService;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWeighbridgeResultAction
{
    /** Fallback تنها زمانی که config مقداردهی نشده باشد — منبع اصلی config است. */
    private const FINANCIAL_TOLERANCE = 1000;

    private function tolerance(): int
    {
        return (int) config('financial.weighbridge_tolerance', self::FINANCIAL_TOLERANCE);
    }

    public function __construct(
        protected WalletTransactionEngine $walletEngine,
        protected SettlementCalculatorService $calculator,
        protected InvoiceGenerator $invoiceGenerator,
        protected InventoryService $inventoryService,
        protected SmsService $smsService,
    ) {}

    public function execute(Order $order, float $actualWeight): array
    {
        if ($this->isAlreadyProcessed($order)) {
            Log::channel('financial')->warning("ProcessWeighbridgeResultAction skipped: order {$order->id} already weighed.");

            return $this->buildAlreadyProcessedResponse($order);
        }

        if ($actualWeight <= 0) {
            throw new \InvalidArgumentException('وزن باسکول باید بزرگ‌تر از صفر باشد.');
        }

        return DB::transaction(function () use ($order, $actualWeight) {

            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            if ($this->isAlreadyProcessed($locked)) {
                Log::channel('financial')->warning("Race detected: order {$locked->id} already weighed.");

                return $this->buildAlreadyProcessedResponse($locked);
            }

            $locked->load('items', 'consultant.badge');

            Log::channel('financial')->info("شروع پردازش باسکول سفارش {$locked->id}.");

            $oldPOO = (float) $locked->payment_of_owner;
            $oldPTS = (float) $locked->payment_to_supplier;

            $wbs = (float) $locked->weight_before_scale;

            // Physical stock is adjusted by the REAL weight delta (what actually left the
            // warehouse): positive → more delivered than ordered, so decrement stock;
            // negative → less delivered, so return the shortfall to stock.
            $weightDiff = $actualWeight - $wbs;
            $firstVariantId = $locked->items->first()?->product_variant_id;
            if ($firstVariantId) {
                $this->inventoryService->updateStockAfterWeighbridge(
                    (int) $firstVariantId,
                    $weightDiff
                );
            }

            // Business invariant (spec "پروسه خرید"): the final invoice is ALWAYS ≤ the
            // initial invoice. The buyer is never billed for more than the ordered weight,
            // so the billing weight is capped at WBS. Over-delivery (actualWeight > WBS) is
            // recorded physically (weight_after_scale + stock) but never charged — there is
            // deliberately no "surplus" payment path.
            $billingWeight = min($actualWeight, $wbs);

            $badgeCode = $locked->consultant?->badge?->code ?? 'pioneer';
            $rates = $this->calculator->getCommissionRates($badgeCode);
            $trans = (float) $locked->shipping_cost;
            $vat = (float) ($locked->vat_rate ?? 10.0);
            $op = ((float) $locked->official_price > 0)
                ? (float) $locked->official_price
                : (float) ($locked->items->first()->unit_price ?? 0);

            // Read formula-variant flags from the order snapshot (written at payment time)
            $transCommission = (bool) ($locked->{Order::TRANS_COMMISSION} ?? true);
            $transVat = (bool) ($locked->{Order::TRANS_VAT} ?? true);

            // POOM = POO recomputed with the (capped) billing weight.
            $newPOO = $this->calculator->calculatePOO(
                $trans, $op, $billingWeight,
                $rates['ow_final_rate'], $vat,
                $transCommission, $transVat
            );
            // PTSM = PTS recomputed with the (capped) billing weight.
            $newPTS = $this->calculator->calculatePTS(
                $trans, $op, $billingWeight,
                $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], $vat,
                $transCommission, $transVat
            );

            $diffPOO = $newPOO - $oldPOO;
            $diffPTS = $newPTS - $oldPTS;

            $status = 'equal';
            $orderStatus = Order::STATUS_SHIPPED;

            $buyerId = $locked->placer_user_id;
            $realSupplierId = $locked->items->first()->supplier_user_id;
            $systemUserId = (int) config('financial.system_user_id', 1);
            $vatUserId = (int) config('financial.vat_user_id', $systemUserId); // P5: separate VAT escrow
            $systemWalletCode = FinancialCodes::WALLET_SAZE;

            // Because billingWeight is capped at WBS, newPOO ≤ oldPOO always: the only
            // non-equal outcome is a deficit (under-delivery). Charging the buyer more than
            // the initial invoice would violate the spec, so there is no surplus branch.
            if ($diffPOO < -$this->tolerance()) {
                $status = 'deficit';
                $refundAmount = abs($diffPOO); // POO − POOM (buyer refund)
                $reclaimAmount = abs($diffPTS); // PTS − PTSM (amount owed by supplier)

                // ── Saze refunds the buyer (POO − POOM): WSSG(−) → WO(?) ─────
                // Saze already holds POO; it pays back the over-paid portion.
                $this->walletEngine->process(
                    '-', $refundAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE,
                    $locked, "پرداخت وجه کسری باسکول سفارش {$locked->order_number} به خریدار",
                    $systemUserId
                );

                // ── Check if supplier can cover the PTS delta right now ────────
                // If the supplier wallet has enough balance, take it immediately
                // and release the buyer refund as AVAILABLE.
                // If not, hold the buyer refund until the supplier tops up.
                $supplierWallet = Wallet::where('user_id', $realSupplierId)->lockForUpdate()->first();
                $supplierCanCover = $supplierWallet && ((float) $supplierWallet->balance >= $reclaimAmount);

                if ($supplierCanCover) {
                    // Supplier pays the delta now — buyer gets refund immediately
                    $this->walletEngine->process(
                        '+', $refundAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE,
                        $locked, 'بازگشت وجه ناشی از کسری باسکول', $buyerId
                    );
                    $this->walletEngine->process(
                        '-', $reclaimAmount, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE,
                        $locked, 'کسر بابت کسری باسکول', $realSupplierId
                    );
                    $this->walletEngine->process(
                        '+', $reclaimAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE,
                        $locked, "بازپس‌گیری از تامین‌کننده بابت کسری باسکول سفارش {$locked->order_number}",
                        $systemUserId
                    );
                } else {
                    // Supplier cannot cover the delta — park buyer refund in HOLD
                    // and notify the supplier to top up their wallet.
                    $this->walletEngine->process(
                        '+', $refundAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_HOLD,
                        $locked, 'بازگشت وجه ناشی از کسری باسکول (در انتظار تسویه سوپلایر)', $buyerId
                    );

                    // Record the pending debt so CheckSupplierBalancesJob can
                    // release the hold automatically when the supplier tops up.
                    $locked->update([
                        Order::SUPPLIER_DEBT_PENDING => true,
                        Order::SUPPLIER_DEBT_AMOUNT => $reclaimAmount,
                    ]);

                    // Notify supplier to top up
                    $supplierUser = $locked->items->first()?->supplier;
                    if ($supplierUser?->mobile) {
                        $this->smsService->send(
                            $supplierUser->mobile,
                            __('sms.supplier_debt_notification', [
                                'order_number' => $locked->order_number,
                                'amount' => number_format((int) $reclaimAmount),
                            ])
                        );
                    }

                    Log::channel('financial')->warning("Supplier {$realSupplierId} insufficient balance for deficit reclaim on order {$locked->id}. Debt: {$reclaimAmount}");
                    $status = 'deficit_pending_supplier';
                }

                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_MODIFIED_INVOICE_OWNER);
                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER);
                // DOC_REFUND_REPORT_WEIGH (M) حذف شد — در سند طراحی وجود ندارد
                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_BILL_OF_LADING);
            } else {
                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_BILL_OF_LADING);
            }

            $vatMultiplier = ($vat / 100) / (1 + ($vat / 100));

            // Consultant gross (PTC) is the %CO slice of the platform commission pool.
            // Deriving it from (POOM − PTSM) inherits the correct ±TC/±VAT handling that
            // calculatePOO()/calculatePTS() already applied, instead of re-deriving an
            // ad-hoc formula that ignored the per-order transport flags:
            //   POOM − PTSM = base × (%SSG + %CO) × (flag/VAT factors)
            //   consultantGross = (POOM − PTSM) × %CO / (%CO + %SSG)
            $commissionPool = $newPOO - $newPTS;
            $coRate = $rates['co_final_rate'];
            $ssgRate = $rates['ssg_final_rate'];
            $candidateGross = ($coRate + $ssgRate) > 0
                ? $commissionPool * ($coRate / ($coRate + $ssgRate))
                : 0.0;

            $consultantUserId = $locked->{Order::CONSULTANT_USER_ID};
            $consultantGross = 0;

            if ($consultantUserId && $consultantUserId !== $buyerId && $candidateGross > 0) {
                // Cap the payout to the consultant's remaining lifetime headroom (spec:
                // ≤ 2× the badge-purchase invoice). Inert until the ceiling is modelled.
                $candidateGross = $this->applyConsultantLifetimeCap(
                    (int) $consultantUserId, (int) $locked->id, $candidateGross
                );

                if ($candidateGross > 0) {
                    $consultantVat = $candidateGross * $vatMultiplier;
                    $consultantNet = $candidateGross - $consultantVat;

                    $this->walletEngine->process('-', $candidateGross, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $locked, "تخصیص سهم مشاور فنی سفارش {$locked->order_number}", $systemUserId);
                    $this->walletEngine->process('+', $consultantNet, FinancialCodes::WALLET_CONSULTANT, FinancialCodes::STATUS_HOLD, $locked, 'کمیسیون امانی مشاور فنی', $consultantUserId);
                    $this->walletEngine->process('+', $consultantVat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD, $locked, 'مالیات کمیسیون مشاور فنی امانی', $vatUserId);

                    $consultantGross = $candidateGross;
                }
            }

            $supplierVat = $newPTS * $vatMultiplier;
            if ($supplierVat > 0) {
                $this->walletEngine->process('-', $supplierVat, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $locked, "کسر مالیات امانی سفارش {$locked->order_number}", $realSupplierId);
                $this->walletEngine->process('+', $supplierVat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD, $locked, 'مالیات تامین‌کننده امانی', $vatUserId);
            }

            $sazeGross = ($newPOO - $newPTS) - $consultantGross;
            $sazeVat = $sazeGross * $vatMultiplier;
            if ($sazeVat > 0) {
                $this->walletEngine->process('-', $sazeVat, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $locked, 'کسر مالیات بر ارزش افزوده پلتفرم', $systemUserId);
                $this->walletEngine->process('+', $sazeVat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD, $locked, 'مالیات گروه سازه امانی', $vatUserId);
            }

            $locked->update([
                Order::WEIGHT_AFTER_SCALE => $actualWeight,
                Order::WEIGHING_COMPLETED_AT => now(),
                'status' => $orderStatus,
                'payment_of_owner' => $newPOO,
                'payment_to_supplier' => $newPTS,
                'payment_to_consultant' => $consultantGross,
                'final_amount' => $newPOO,
            ]);

            Log::channel('financial')->info("باسکول نهایی شد. وضعیت: {$status}");

            return [
                'status' => $status,
                'old_weight' => (string) $locked->weight_before_scale,
                'new_weight' => (string) $actualWeight,
                'financial_diff' => (string) abs($diffPOO),
                'final_amount' => (string) $newPOO,
            ];
        }, 3);
    }

    /**
     * Cap a consultant's commission payout to their remaining lifetime headroom.
     *
     * Spec: the total lifetime commission paid to a gold/silver/bronze consultant may
     * not exceed `consultant_lifetime_cap_multiplier` × the invoice they paid when
     * buying their badge. Returns the (possibly reduced) gross — never below zero.
     * When no ceiling is configured (the common case today) the candidate passes through
     * unchanged, so current behaviour is preserved.
     */
    private function applyConsultantLifetimeCap(int $consultantUserId, int $currentOrderId, float $candidateGross): float
    {
        $ceiling = $this->consultantLifetimeCeiling($consultantUserId);
        if ($ceiling === null) {
            return $candidateGross;
        }

        $alreadyPaid = (float) Order::query()
            ->where(Order::CONSULTANT_USER_ID, $consultantUserId)
            ->whereNotNull(Order::WEIGHING_COMPLETED_AT)
            ->where(Order::ID, '!=', $currentOrderId)
            ->sum(Order::PAYMENT_TO_CONSULTANT);

        $headroom = max(0.0, $ceiling - $alreadyPaid);

        return min($candidateGross, $headroom);
    }

    /**
     * Lifetime commission ceiling for a consultant, or null when uncapped.
     *
     * TODO(product): the cap equals `consultant_lifetime_cap_multiplier` × the
     * consultant's badge-purchase invoice amount. That per-consultant amount is not
     * modelled yet, so this returns null (uncapped). Resolve it here — e.g.
     *   $base = ...badge-purchase invoice for $consultantUserId...;
     *   return $base * (float) config('financial.consultant_lifetime_cap_multiplier', 2.0);
     */
    private function consultantLifetimeCeiling(int $consultantUserId): ?float
    {
        return null;
    }

    private function isAlreadyProcessed(Order $order): bool
    {
        return $order->weighing_completed_at !== null;
    }

    private function buildAlreadyProcessedResponse(Order $order): array
    {
        return [
            'status' => 'already_processed',
            'old_weight' => (string) $order->weight_before_scale,
            'new_weight' => (string) $order->weight_after_scale,
            'financial_diff' => '0',
            'final_amount' => (string) $order->final_amount,
        ];
    }
}
