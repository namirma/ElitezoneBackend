<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderUnit;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCartItemAction
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    public function execute(CartItem $cartItem, array $data): CartItem
    {
        // بارگذاری روابط ضروری برای جلوگیری از N+1
        $cartItem->loadMissing(['productVariant.product.orderUnits', 'productVariant.limitOrderUnit', 'cart']);
        $variant = $cartItem->productVariant;

        // دریافت نگاشت واحدها
        $orderUnitsMap = $this->getOrderUnitsMap();

        return DB::transaction(function () use ($cartItem, $variant, $data, $orderUnitsMap) {

            // 1. اگر تعداد تغییر کرده است، تمام محدودیت‌ها را چک کن
            if (isset($data['quantities'])) {
                $newQuantities = $data['quantities'];

                // الف: بررسی موجودی انبار
                // نکته: متد checkStock در سرویس شما باید هوشمند باشد و آرایه را هندل کند
                if (!$this->inventoryService->checkStock($variant, $newQuantities)) {
                    throw ValidationException::withMessages([
                        'quantities' => __('cart.requested_quantity_not_available')
                    ]);
                }

                // ب: بررسی سقف سفارش برای هر واحد (مثلاً حداکثر 50 کارتن)
                // ما خود آیتم فعلی را پاس می‌دهیم تا در محاسبه "تعداد فعلی سبد"، مقدار خودش را نادیده بگیرد
                $this->validateOrderUnitCounts($variant, $newQuantities, $cartItem->cart, $cartItem);

                // ج: بررسی محدودیت‌های مجموع واریانت (Min/Max Order Value برای این کالا)
                $this->checkVariantAggregateLimits($variant, $newQuantities, $orderUnitsMap);

                // آپدیت تعداد در مدل
                $cartItem->quantities = $newQuantities;
            }

            // 2. سایر فیلدها
            if (isset($data['shipping_method_id'])) {
                $cartItem->shipping_method_id = $data['shipping_method_id'];
            }

            if (isset($data['factory_location_id'])) {
                $cartItem->factory_location_id = $data['factory_location_id'];
            }

            // 3. ذخیره تغییرات
            $cartItem->save();

            // 4. بررسی محدودیت‌های کل گروه محصول (Product Line)
            // نکته مهم: این چک باید "بعد از ذخیره" انجام شود تا محاسبات روی مقادیر جدید سبد اعمال شود.
            // اگر محدودیت نقض شود، Exception پرتاب شده و تراکنش Rollback می‌شود (تغییرات برمی‌گردد).

            // رفرش کردن سبد برای گرفتن مقادیر جدید آیتم‌ها
            $freshCart = $cartItem->cart->fresh(['items.productVariant.product', 'items.productVariant.product.aggregateLimitOrderUnit']);
            $this->checkProductLineAggregateLimits($freshCart, $variant->product, $orderUnitsMap);

            return $cartItem;
        });
    }

    // =========================================================================
    //  متدهای کمکی (مشابه AddToCartAction برای یکپارچگی منطق)
    // =========================================================================

    protected function getOrderUnitsMap(): Collection
    {
        return Cache::remember('order_units_map', 86400, function () {
            return OrderUnit::all()->keyBy(OrderUnit::ID);
        });
    }

    protected function convertQuantitiesToTargetUnitValue(array $itemQuantities, ?OrderUnit $targetLimitOrderUnit, Collection $orderUnitsMap): ?float
    {
        if (!$targetLimitOrderUnit || $targetLimitOrderUnit->amount == 0) {
            return null;
        }

        $totalValueInBase = 0;
        foreach ($itemQuantities as $unitId => $count) {
            $currentOrderUnit = $orderUnitsMap->get((int)$unitId);
            if ($currentOrderUnit && $currentOrderUnit->amount > 0) {
                $totalValueInBase += (float)$count * $currentOrderUnit->amount;
            }
        }

        return $totalValueInBase / $targetLimitOrderUnit->amount;
    }

    protected function validateOrderUnitCounts(ProductVariant $variant, array $newQuantities, Cart $cart, CartItem $currentItem): void
    {
        $unitMap = $variant->product->orderUnits->keyBy('pivot.order_unit_id');

        foreach ($newQuantities as $unitId => $qty) {
            $unitId = (int)$unitId;
            $qty = (float)$qty;

            $unit = $unitMap->get($unitId);
            if (!$unit) {
                throw ValidationException::withMessages(['quantities.' . $unitId => ["واحد سفارش با شناسه {$unitId} نامعتبر است."]]);
            }

            if ($qty < 0) {
                throw ValidationException::withMessages(['quantities.' . $unitId => ["تعداد واحد '{$unit->name}' نمی‌تواند منفی باشد."]]);
            }

            $maxCount = $unit->pivot->max_quantity ?? PHP_INT_MAX;

            // محاسبه تعداد این واحد در "سایر" آیتم‌های سبد (به جز آیتم فعلی که داریم ویرایش می‌کنیم)
            $othersInCart = $cart->items
                ->where('id', '!=', $currentItem->id) // <--- نکته کلیدی اینجاست
                ->where('product_variant_id', $variant->id)
                ->sum(fn($item) => $item->quantities[(string)$unitId] ?? ($item->quantities[(int)$unitId] ?? 0));

            // جمع تعداد بقیه + تعداد جدید درخواستی
            if (($othersInCart + $qty) > $maxCount) {
                throw ValidationException::withMessages(['quantities.' . $unitId => [
                    "حداکثر مجاز برای '{$unit->name}' برابر {$maxCount} است. با تغییر جدید، مجموع به " . ($othersInCart + $qty) . " می‌رسد."
                ]]);
            }
        }
    }

    protected function checkVariantAggregateLimits(ProductVariant $variant, array $quantities, Collection $orderUnitsMap): void
    {
        $minValue = $variant->min_order_value;
        $maxValue = $variant->max_order_value;
        $limitUnitId = $variant->limit_order_unit_id;

        if ($limitUnitId === null || ($minValue === null && $maxValue === null)) return;

        if (!$variant->relationLoaded('limitOrderUnit')) $variant->load('limitOrderUnit');
        $limitUnit = $variant->limitOrderUnit;

        if (!$limitUnit) return;

        $totalValue = $this->convertQuantitiesToTargetUnitValue($quantities, $limitUnit, $orderUnitsMap);

        if ($totalValue === null) return;

        $variantName = $variant->name;
        $unitName = $limitUnit->name;

        if ($minValue !== null && $totalValue > 0.0001 && $totalValue < $minValue) {
            throw ValidationException::withMessages(['quantities' => [
                "حداقل سفارش برای '{$variantName}' باید {$minValue} {$unitName} باشد. فعلی: " . round($totalValue, 2)
            ]]);
        }

        if ($maxValue !== null && $totalValue > $maxValue) {
            throw ValidationException::withMessages(['quantities' => [
                "حداکثر سفارش برای '{$variantName}' برابر {$maxValue} {$unitName} است."
            ]]);
        }
    }

    protected function checkProductLineAggregateLimits(Cart $cart, Product $product, Collection $orderUnitsMap): void
    {
        $minValue = $product->aggregate_min_order_value;
        $maxValue = $product->aggregate_max_order_value;
        $limitUnitId = $product->aggregate_limit_order_unit_id;

        if ($limitUnitId === null || ($minValue === null && $maxValue === null)) return;

        if (!$product->relationLoaded('aggregateLimitOrderUnit')) $product->load('aggregateLimitOrderUnit');
        $limitUnit = $product->aggregateLimitOrderUnit;

        if (!$limitUnit || $limitUnit->amount == 0) return;

        $totalValueInBase = 0;

        // حلقه روی آیتم‌های سبد (که الان شامل مقادیر جدید آپدیت شده هستند)
        foreach ($cart->items as $item) {
            if ($item->productVariant->product_id === $product->id) {
                foreach ($item->quantities as $unitId => $count) {
                    $unit = $orderUnitsMap->get((int)$unitId);
                    if ($unit && $unit->amount > 0) {
                        $totalValueInBase += (float)$count * $unit->amount;
                    }
                }
            }
        }

        $totalValue = $totalValueInBase / $limitUnit->amount;
        $productName = $product->name;
        $unitName = $limitUnit->name;

        if ($minValue !== null && $totalValue > 0.0001 && $totalValue < $minValue) {
            throw ValidationException::withMessages(['product_line' => [
                "حداقل مجموع سفارش برای گروه '{$productName}' باید {$minValue} {$unitName} باشد. مقدار فعلی: " . round($totalValue, 2)
            ]]);
        }

        if ($maxValue !== null && $totalValue > $maxValue) {
            throw ValidationException::withMessages(['product_line' => [
                "حداکثر مجموع سفارش برای گروه '{$productName}' برابر {$maxValue} {$unitName} است."
            ]]);
        }
    }
}
