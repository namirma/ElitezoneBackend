<?php

namespace App\Services;

use App\Exceptions\Order\StockUnavailableException;
use App\Models\FactoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class InventoryService
{
    /**
     * بررسی موجودی قبل از ثبت سفارش (بدون کسر کردن).
     *
     * مبدأ (کارخانه/انبار) اختیاری است تا فراخوان‌های قدیمی نشکنند، ولی **همیشه
     * بدهیدش**: موجودی به‌ازای مبدأ نگه داشته می‌شود و بدون آن به عددِ سراسری
     * برمی‌گردیم که فقط جمعِ نمایشی است و برای هیچ مبدأ خاصی درست نیست.
     */
    public function checkStock(
        ProductVariant $variant,
        array|float|int $quantities,
        ?int $factoryId = null,
        ?int $factoryLocationId = null,
    ): bool {
        $requiredBaseQty = is_array($quantities)
            ? $this->calculateBaseQuantity($quantities, $variant)
            : (float) $quantities;

        return $this->availableAt($variant, $factoryId, $factoryLocationId) >= ($requiredBaseQty - 0.0001);
    }

    /**
     * موجودیِ قابلِ فروشِ یک واریانت در یک مبدأ.
     *
     * نبودنِ ردیف یعنی «این مبدأ هنوز موجودیِ اختصاصی ثبت نکرده»، نه «صفر» — پس
     * به عددِ سراسری برمی‌گردیم. اگر صفر فرض می‌کردیم، هر سه‌تاییِ قیمت‌داری که
     * بعد از backfill اضافه شده بی‌صدا غیرقابل‌فروش می‌شد.
     */
    public function availableAt(ProductVariant $variant, ?int $factoryId, ?int $factoryLocationId): float
    {
        if ($factoryId && $factoryLocationId) {
            $row = FactoryStock::query()
                ->where(FactoryStock::PRODUCT_VARIANT_ID, $variant->id)
                ->where(FactoryStock::FACTORY_ID, $factoryId)
                ->where(FactoryStock::FACTORY_LOCATION_ID, $factoryLocationId)
                ->first();

            if ($row) {
                return (float) $row->{FactoryStock::QUANTITY};
            }
        }

        return (float) $variant->stock_quantity;
    }

    /**
     * کسر موجودی برای اقلام یک سفارش
     *
     * @throws StockUnavailableException
     */
    public function deductStockForOrder(Order $order): void
    {
        $order->loadMissing(['items.productVariant']);

        foreach ($order->items as $item) {
            $this->deductItemStock($item);
        }
    }

    /**
     * اصلاح موجودیِ **کلِ سفارش** پس از باسکول.
     *
     * باسکول یک عددِ واحد می‌دهد (وزنِ واقعیِ کلِ بار)، ولی سفارش می‌تواند چند ردیف
     * داشته باشد. تا پیش از این کلِ اختلافِ وزن روی **اولین واریانت** اعمال می‌شد:
     * سفارشِ میلگرد ۱۴ + میلگرد ۱۶ با ۲۰۰ کیلو کسری، هر ۲۰۰ کیلو را از میلگرد ۱۴
     * کم می‌کرد — موجودیِ هر دو غلط می‌شد. گاردِ `assertSingleSupplier` این را
     * نمی‌گیرد چون تعدادِ *تأمین‌کننده* را می‌شمارد نه تعدادِ *ردیف*، و سبدی با دو
     * کالا از یک کارخانه کاملاً عادی است.
     *
     * چون باسکول نمی‌گوید کسری از کدام کالا بوده، سهم‌بندی **به‌نسبتِ وزنِ سفارشِ
     * هر ردیف** انجام می‌شود — همان قاعده‌ای که `ShippingCostCalculator` برای
     * تقسیمِ کرایه بین ردیف‌ها به کار می‌برد. آخرین سهم باقیمانده را جذب می‌کند تا
     * جمعِ سهم‌ها دقیقاً برابرِ اختلافِ باسکول بماند.
     *
     * چند ردیف می‌توانند به یک واریانت اشاره کنند؛ اول تجمیع می‌شوند تا هر واریانت
     * یک بار قفل و به‌روز شود و کفِ صفرِ متدِ زیر یک‌جا اعمال شود.
     */
    public function updateStockAfterWeighbridgeForOrder(Order $order, float $weightDifference): void
    {
        if (abs($weightDifference) < 0.001) {
            return;
        }

        $order->loadMissing('items');

        // کلید حالا سه‌تاییِ (واریانت، کارخانه، انبار) است نه فقط واریانت: اصلاحِ
        // باسکول باید به همان مبدأیی برگردد که بار از آن رفته، وگرنه کسری از
        // موجودیِ مبدأ دیگری کم می‌شد.
        $weightByOrigin = [];
        foreach ($order->items as $item) {
            $variantId = (int) $item->product_variant_id;
            $itemWeight = (float) $item->weight;

            if ($variantId <= 0 || $itemWeight <= 0) {
                continue;
            }

            $key = implode(':', [
                $variantId,
                (int) $item->{OrderItem::FACTORY_ID},
                (int) $item->{OrderItem::FACTORY_LOCATION_ID},
            ]);

            $weightByOrigin[$key] = ($weightByOrigin[$key] ?? 0.0) + $itemWeight;
        }

        $totalWeight = array_sum($weightByOrigin);

        if ($totalWeight <= 0) {
            // بدون وزنِ ردیفی نمی‌شود سهم‌بندی کرد. سکوت نمی‌کنیم: این یعنی
            // سفارشی به باسکول رسیده که ردیف‌هایش وزن ندارند — داده‌ی خراب.
            Log::channel('orders')->warning(
                "updateStockAfterWeighbridgeForOrder: order {$order->id} has no line weights; stock left untouched."
            );

            return;
        }

        $allocated = 0.0;
        $lastKey = array_key_last($weightByOrigin);

        foreach ($weightByOrigin as $key => $originWeight) {
            // آخرینی باقیمانده را می‌گیرد تا خطای گردکردن انباشته نشود.
            $share = $key === $lastKey
                ? $weightDifference - $allocated
                : round($weightDifference * ($originWeight / $totalWeight), 3);

            $allocated += $share;

            [$variantId, $factoryId, $locationId] = array_map('intval', explode(':', $key));

            $this->updateStockAtOrigin($variantId, $factoryId ?: null, $locationId ?: null, $share);
        }
    }

    /**
     * اصلاحِ موجودیِ یک مبدأ پس از باسکول. سهم مثبت یعنی کسری (کم می‌کنیم)،
     * منفی یعنی اضافه‌تحویل (برمی‌گردانیم).
     */
    private function updateStockAtOrigin(int $variantId, ?int $factoryId, ?int $locationId, float $share): void
    {
        if (abs($share) < 0.001) {
            return;
        }

        $stock = FactoryStock::lockRow($variantId, $factoryId, $locationId);

        if (! $stock) {
            // مبدأ ردیفِ اختصاصی ندارد — همان مسیرِ لگاسیِ سراسری.
            $this->updateStockAfterWeighbridge($variantId, $share);

            return;
        }

        if ($share > 0) {
            // کف‌بندی روی صفر: اختلافِ باسکولِ بزرگ‌تر از موجودیِ باقی‌مانده
            // وگرنه ستون را منفی می‌کرد و از آن به بعد هر چکِ موجودی می‌شکست.
            $stock->decrement(
                FactoryStock::QUANTITY,
                min($share, (float) $stock->{FactoryStock::QUANTITY})
            );
        } else {
            $stock->increment(FactoryStock::QUANTITY, abs($share));
        }

        FactoryStock::syncVariantRollup($variantId);
    }

    /**
     * اصلاح موجودیِ یک واریانت بعد از ثبت باسکول (Weighbridge).
     *
     * برای سفارشِ چندردیفی مستقیماً صدا زده نشود —
     * `updateStockAfterWeighbridgeForOrder()` سهم‌بندی را انجام می‌دهد.
     */
    public function updateStockAfterWeighbridge(int $variantId, float $weightDifference): void
    {
        if (abs($weightDifference) < 0.001) {
            return;
        }

        $variant = ProductVariant::where('id', $variantId)->lockForUpdate()->first();
        if (! $variant) {
            return;
        }

        if ($weightDifference > 0) {
            // کف‌بندی روی صفر — deductItemStock گاردِ موجودی دارد ولی این متد نداشت،
            // پس یک اختلافِ باسکولِ بزرگ‌تر از موجودیِ باقی‌مانده می‌توانست
            // stock_quantity را منفی کند و از آن به بعد هر checkStock را بشکند.
            $variant->decrement('stock_quantity', min($weightDifference, (float) $variant->stock_quantity));
        } else {
            $variant->increment('stock_quantity', abs($weightDifference));
        }
    }

    /**
     * کسر موجودی یک آیتم خاص — از **مبدأ خودش**.
     *
     * هر ردیفِ سفارش `factory_id`/`factory_location_id` را دارد (همان چیزی که
     * زیرسیستمِ حمل برای مبدأ استفاده می‌کند)، پس کسر دقیقاً از همان مبدأیی
     * انجام می‌شود که کالا از آن فروخته شده. پیش از این از یک استخرِ سراسری کم
     * می‌شد و فروشِ یک تأمین‌کننده موجودیِ تأمین‌کننده‌ی دیگر را کم می‌کرد.
     */
    private function deductItemStock(OrderItem $item): void
    {
        $variant = ProductVariant::where('id', $item->product_variant_id)
            ->lockForUpdate()
            ->first();

        if (! $variant) {
            throw new ModelNotFoundException(
                __('inventory.errors.product_not_found', ['id' => $item->product_variant_id])
            );
        }

        $amountToDeduct = ($item->weight > 0) ? (float) $item->weight : (float) $item->quantity;

        if ($amountToDeduct <= 0) {
            $quantities = is_string($item->quantity_details)
                ? json_decode($item->quantity_details, true)
                : $item->quantity_details;

            $amountToDeduct = $this->calculateBaseQuantity($quantities, $variant);
        }

        $factoryId = (int) $item->{OrderItem::FACTORY_ID} ?: null;
        $locationId = (int) $item->{OrderItem::FACTORY_LOCATION_ID} ?: null;

        $stock = FactoryStock::lockRow((int) $variant->id, $factoryId, $locationId);
        $available = $stock ? (float) $stock->{FactoryStock::QUANTITY} : (float) $variant->stock_quantity;

        if ($available < ($amountToDeduct - 0.0001)) {
            throw new StockUnavailableException(
                __('inventory.errors.insufficient_stock', [
                    'name' => $variant->name,
                    'requested' => number_format($amountToDeduct),
                    'available' => number_format($available),
                ])
            );
        }

        if ($stock) {
            $stock->decrement(FactoryStock::QUANTITY, $amountToDeduct);
            FactoryStock::syncVariantRollup((int) $variant->id);

            return;
        }

        // مبدأ هنوز ردیفِ اختصاصی ندارد — رفتارِ لگاسی روی عددِ سراسری.
        $variant->decrement('stock_quantity', $amountToDeduct);
    }

    /**
     * بازگرداندن موجودی (در صورت لغو سفارش)
     */
    public function restoreStock(Order $order): void
    {
        $order->loadMissing(['items.productVariant']);

        foreach ($order->items as $item) {
            $amountRestored = ($item->weight > 0) ? (float) $item->weight : (float) $item->quantity;

            if ($amountRestored <= 0) {
                $quantities = is_string($item->quantity_details)
                    ? json_decode($item->quantity_details, true)
                    : $item->quantity_details;

                $variant = $item->productVariant;
                $amountRestored = $this->calculateBaseQuantity($quantities, $variant);
            }

            if ($amountRestored > 0) {
                // به همان مبدأیی برمی‌گردد که از آن کم شده بود — وگرنه لغوِ سفارش
                // موجودی را از تأمین‌کننده‌ی A به استخرِ سراسری منتقل می‌کرد.
                $stock = FactoryStock::lockRow(
                    (int) $item->product_variant_id,
                    (int) $item->{OrderItem::FACTORY_ID} ?: null,
                    (int) $item->{OrderItem::FACTORY_LOCATION_ID} ?: null,
                );

                if ($stock) {
                    $stock->increment(FactoryStock::QUANTITY, $amountRestored);
                    FactoryStock::syncVariantRollup((int) $item->product_variant_id);

                    continue;
                }

                ProductVariant::where('id', $item->product_variant_id)
                    ->increment('stock_quantity', $amountRestored);
            }
        }
    }

    /**
     * محاسبه مقدار پایه (کیلوگرم یا تعداد) از روی جزئیات
     */
    private function calculateBaseQuantity(?array $details, ?ProductVariant $variant = null): float
    {
        if (empty($details)) {
            return 0.0;
        }

        $unitsMap = OrderUnit::allCached();

        $totalBaseQty = 0.0;

        foreach ($details as $unitId => $qty) {
            $qty = (float) $qty;
            $unit = $unitsMap->get((int) $unitId);

            if ($unit) {
                $totalBaseQty += $qty * (float) $unit->amount;
            } else {
                $variantWeight = ($variant && $variant->weight > 0) ? (float) $variant->weight : 1.0;
                $totalBaseQty += $qty * $variantWeight;
            }
        }

        return $totalBaseQty;
    }
}
