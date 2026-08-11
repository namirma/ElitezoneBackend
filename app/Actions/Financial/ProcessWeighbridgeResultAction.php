<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Models\Order;
use App\Services\Financial\SupplierDebtHandler;
use App\Services\Financial\WeighbridgeReconciler;
use App\Services\Financial\WeighbridgeVatRouter;
use App\Services\InventoryService;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWeighbridgeResultAction
{
    private const FINANCIAL_TOLERANCE = 1000;

    private function tolerance(): int
    {
        return (int) config('financial.weighbridge_tolerance', self::FINANCIAL_TOLERANCE);
    }

    public function __construct(
        protected WeighbridgeReconciler $reconciler,
        protected WeighbridgeVatRouter $vatRouter,
        protected SupplierDebtHandler $debtHandler,
        protected InvoiceGenerator $invoiceGenerator,
        protected InventoryService $inventoryService,
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

            // گارد وضعیت: باسکول فقط برای سفارشِ پرداخت‌شده در پایپلاین آماده‌سازی معناست.
            // بدون این گارد، ثبت وزن روی سفارش PENDING/لغوشده، محاسبات را روی snapshot های
            // مالیِ ثبت‌نشده (POO/PTS صفر) اجرا می‌کرد و وضعیت را به SHIPPED می‌پراند.
            $weighableStatuses = [
                Order::STATUS_PROCESSING,
                Order::STATUS_SHIPPED,
            ];
            if (
                $locked->{Order::PAYMENT_STATUS} !== Order::PAYMENT_PAID
                || ! in_array($locked->{Order::STATUS}, $weighableStatuses, true)
            ) {
                throw new \RuntimeException(__('weighbridge.errors.not_weighable', [
                    'status' => $locked->{Order::STATUS},
                ]));
            }

            // larastan نتیجهٔ `lockForUpdate()->first()` را `Model` می‌بیند نه `Order`؛
            // گاردهای بالا این تنگ‌سازی را تضمین می‌کنند و بدون آن، فراخوانیِ
            // متدهایی که `Order` می‌خواهند یک ورودیِ تازه به baseline اضافه می‌کرد.
            /** @var Order $locked */
            $locked->load('items', 'eliteYAR.badge');

            Log::channel('financial')->info("شروع پردازش باسکول سفارش {$locked->id}.");

            // Physical stock adjusts by the REAL weight delta (not the billing delta).
            //
            // اختلاف بین **همهٔ** ردیف‌ها به‌نسبتِ وزنشان تقسیم می‌شود. پیش از این
            // کلِ اختلاف روی `items->first()` می‌نشست، پس در سفارشِ چندردیفی
            // موجودیِ ردیفِ اول بیش از حد و بقیه اصلاً اصلاح نمی‌شد.
            $weightDiff = $actualWeight - (float) $locked->weight_before_scale;
            $this->inventoryService->updateStockAfterWeighbridgeForOrder($locked, $weightDiff);

            $result = $this->reconciler->reconcile($locked, $actualWeight);

            $isDeficit = $result->diffPOO < -$this->tolerance();
            $status = 'equal';
            if ($isDeficit) {
                $status = $this->debtHandler->handle($locked, abs($result->diffPOO), abs($result->diffPTS));
            }
            // Doc I (always) + LO/LS (only when deficit)
            $this->invoiceGenerator->generateWeighbridgeDocs($locked, $isDeficit);

            $eliteYARGross = $this->vatRouter->route($locked, $result);

            $locked->forceFill([
                Order::WEIGHT_AFTER_SCALE => $actualWeight,
                Order::WEIGHING_COMPLETED_AT => now(),
                Order::STATUS => Order::STATUS_SHIPPED,
                Order::PAYMENT_OF_OWNER => $result->newPOO,
                Order::PAYMENT_TO_SUPPLIER => $result->newPTS,
                Order::PAYMENT_TO_ELITE_YAR => $eliteYARGross,
                Order::FINAL_AMOUNT => $result->newPOO,
            ])->save();

            Log::channel('financial')->info("باسکول نهایی شد. وضعیت: {$status}");

            return [
                'status' => $status,
                'old_weight' => (string) $locked->weight_before_scale,
                'new_weight' => (string) $actualWeight,
                'financial_diff' => (string) abs($result->diffPOO),
                'final_amount' => (string) $result->newPOO,
            ];
        }, 3);
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
