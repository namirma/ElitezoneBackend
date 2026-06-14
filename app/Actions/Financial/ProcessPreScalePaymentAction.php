<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\SupplierProfile;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPreScalePaymentAction
{
    public function __construct(
        protected SettlementCalculatorService $calculator,
        protected WalletTransactionEngine $walletEngine,
        protected InvoiceGenerator $invoiceGenerator,
    ) {}

    public function execute(Order $order): void
    {
        if ($this->isAlreadyProcessed($order)) {
            Log::channel('financial')->warning("ProcessPreScalePaymentAction skipped: order {$order->id} already paid.");

            return;
        }

        DB::transaction(function () use ($order) {

            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            if ($this->isAlreadyProcessed($locked)) {
                Log::channel('financial')->warning("Race detected: order {$locked->id} already paid.");

                return;
            }

            $locked->load('items', 'beneficiary.badge');

            $firstItem = $locked->items->first();

            if (! $firstItem) {
                Log::channel('financial')->error("Order {$locked->id} has no items.");
                throw new \RuntimeException(__('orders.items_missing_for_payment'));
            }

            $officialPrice = (float) $locked->getAttribute(Order::OFFICIAL_PRICE);
            if ($officialPrice <= 0) {
                $officialPrice = (float) $firstItem->unit_price;
            }

            $weightBeforeScale = (float) ($locked->getAttribute(Order::WEIGHT_BEFORE_SCALE) ?? 0);
            $shippingCost = (float) ($locked->shipping_cost ?? 0);
            $vatRate = (float) ($locked->vat_rate ?? 10.0);

            $badgeCode = $locked->beneficiary?->badge?->code ?? 'pioneer';
            $rates = $this->calculator->getCommissionRates($badgeCode);

            $ownerDiscountRate = $rates['ow_final_rate'];
            $sazeShareRate = $rates['ssg_final_rate'];
            $consultantRate = $rates['co_final_rate'];

            // ── Snapshot formula-variant flags from supplier contract ──────────
            $realSupplierId = $firstItem->supplier_user_id;
            $supplierProfile = SupplierProfile::where(SupplierProfile::USER_ID, $realSupplierId)->first();
            $transCommission = (bool) ($supplierProfile?->{SupplierProfile::TRANS_COMMISSION} ?? true);
            $transVat = (bool) ($supplierProfile?->{SupplierProfile::TRANS_VAT} ?? true);

            // ── Calculate POO and PTS using the correct formula variant ────────
            $POO = $this->calculator->calculatePOO(
                $shippingCost, $officialPrice, $weightBeforeScale,
                $ownerDiscountRate, $vatRate,
                $transCommission, $transVat
            );
            $PTS = $this->calculator->calculatePTS(
                $shippingCost, $officialPrice, $weightBeforeScale,
                $ownerDiscountRate, $sazeShareRate, $consultantRate, $vatRate,
                $transCommission, $transVat
            );

            // ── Snapshot all financial parameters onto the order ───────────────
            $locked->update([
                Order::PAYMENT_OF_OWNER => $POO,
                Order::PAYMENT_TO_SUPPLIER => $PTS,
                Order::COMMISSION_RATE => 1.0,
                Order::VAT_RATE => $vatRate,
                Order::OWNER_DISCOUNT_RATE => $ownerDiscountRate,
                Order::TRANS_COMMISSION => $transCommission,
                Order::TRANS_VAT => $transVat,
            ]);

            // A — buyer pays POO: WO(-) → WSSG(+)
            $this->walletEngine->debitBuyer(
                $locked, $POO, FinancialCodes::STATUS_AVAILABLE,
                "پرداخت وجه سفارش {$locked->order_number}"
            );
            $this->walletEngine->creditSystem(
                $locked, $POO, FinancialCodes::STATUS_AVAILABLE,
                "دریافت وجه از خریدار برای سفارش {$locked->order_number}"
            );

            // B — Saze pays supplier PTS: WSSG(-) → WS(+)
            $this->walletEngine->debitSystem(
                $locked, $PTS, FinancialCodes::STATUS_AVAILABLE,
                "تخصیص سهم تامین‌کننده سفارش {$locked->order_number}"
            );
            $this->walletEngine->creditSupplier(
                $locked, $PTS, FinancialCodes::STATUS_AVAILABLE, $realSupplierId,
                "واریز سهم فروش سفارش {$locked->order_number}"
            );

            $locked->update([
                'status' => Order::STATUS_PROCESSING,
                'payment_status' => Order::PAYMENT_PAID,
                'paid_at' => now(),
            ]);

            // ── صدور اسناد مالی خرید مستقیم (A + B + C، با D در صورت داشتن مشاور) ──
            $this->invoiceGenerator->generateDirectPurchaseDocs(
                $locked,
                withConsultant: $locked->{Order::BENEFICIARY_USER_ID} !== null
            );

            Log::channel('financial')->info("پرداخت سفارش {$locked->id} با موفقیت انجام شد. فرمول: TC={$transCommission} VAT={$transVat}");
        }, 3);
    }

    private function isAlreadyProcessed(Order $order): bool
    {
        return $order->payment_status === Order::PAYMENT_PAID;
    }
}
