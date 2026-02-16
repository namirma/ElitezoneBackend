<?php

namespace App\Actions\Order;

use App\DataTransferObjects\PlaceOrderData;
use App\Events\OrderPlaced;
use App\Exceptions\Order\CartIsEmptyException;
use App\Models\Cart;
use App\Models\CustomerType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit;
use App\Models\PriceFilterCategory;
use App\Models\User;
use App\Models\UserType;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\InventoryService;
use App\Services\PriceCalculationService;
use App\Services\PricingRoleResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlaceOrderAction
{
    public function __construct(
        private readonly PriceCalculationService $priceService,
        private readonly PricingRoleResolver $roleResolver,
        private readonly InventoryService $inventoryService,
        private readonly SettlementCalculatorService $settlementCalculator
    ) {}

    public function execute(User $user, PlaceOrderData $data): Order
    {
        $cart = $this->getCartOrFail($user);

        // محاسبه مالی
        $financials = $this->calculateOrderFinancials($cart, $user, $data);

        $order = DB::transaction(function () use ($user, $cart, $data, $financials) {
            // ساخت سفارش
            $order = $this->createOrderRecord($user, $data, $financials);

            // ساخت آیتم‌ها (با مقادیر محاسبه شده)
            $this->createOrderItems($order, $cart, $financials['items_data']);

            // کسر موجودی
            $this->inventoryService->deductStockForOrder($order);

            // پاکسازی سبد
            $this->clearCart($cart);

            return $order;
        });

        OrderPlaced::dispatch($order);
        return $order;
    }

    private function getCartOrFail(User $user): Cart
    {
        $cart = Cart::with([
            'items.productVariant.product',
            'items.factory',
            'items.factoryLocation',
            'customerType',
            'badge',
            'priceFilterCategories'
        ])
        ->where('user_id', $user->id)
        ->latest('updated_at')
        ->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw new CartIsEmptyException(__('orders.errors.cart_empty'));
        }

        return $cart;
    }

    private function calculateOrderFinancials(Cart $cart, User $user, PlaceOrderData $data): array
    {
        $subtotal = 0.0;
        $totalVat = 0.0;
        $totalDiscount = 0.0;
        $totalWeight = 0.0;
        $itemsData = [];

        $roleCode = $this->roleResolver->resolve($user);
        $customerType = $cart->customerType ?? CustomerType::where('code', $roleCode)->first();

        // کش کردن واحدها
        $orderUnits = Cache::remember('order_units_all', 3600, fn() => OrderUnit::all());

        $activeFilter = PriceFilterCategory::where('code', $roleCode)->first();
        $filterIds = $activeFilter ? [$activeFilter->id] : $cart->priceFilterCategories->pluck('id')->toArray();

        foreach ($cart->items as $item) {
            $pricing = $this->priceService->calculatePrice(
                variant: $item->productVariant,
                factory: $item->factory,
                location: $item->factoryLocation,
                customerType: $customerType,
                badge: $cart->badge,
                filterCategoryIds: $filterIds,
                withVAT: true,
                filterCategoryCode: $roleCode
            );

            // --- منطق محاسبه وزن و تعداد ---
            $itemLogisticsWeight = 0.0;
            $itemSalesQuantity = 0.0;
            $primaryUnitName = null;

            $quantities = $item->quantities;
            if (is_string($quantities)) $quantities = json_decode($quantities, true);
            if (!is_array($quantities)) $quantities = [];

            if (!empty($quantities)) {
                foreach ($quantities as $unitId => $qty) {
                    $qty = (float) $qty;
                    $itemSalesQuantity += $qty;

                    $unitModel = $orderUnits->where('id', $unitId)->first();
                    if (!$primaryUnitName && $unitModel) {
                        $primaryUnitName = $unitModel->name;
                    }

                    if ($unitModel) {
                        $itemLogisticsWeight += $qty * (float) $unitModel->amount;
                    } else {
                        $variantWeight = (float) ($item->productVariant->weight ?? 0);
                        if ($variantWeight <= 0) $variantWeight = 1;
                        $itemLogisticsWeight += $qty * $variantWeight;
                    }
                }
            } else {
                $simpleQty = (float) ($item->quantity ?? 1);
                $itemSalesQuantity = $simpleQty;
                $primaryUnitName = 'عدد';
                $variantWeight = (float) ($item->productVariant->weight ?? 0);
                if ($variantWeight <= 0) $variantWeight = 1;
                $itemLogisticsWeight = $simpleQty * $variantWeight;
            }

            if ($itemLogisticsWeight <= 0) $itemLogisticsWeight = 0.001;
            $totalWeight += $itemLogisticsWeight;

            $lineTotal = $itemLogisticsWeight * $pricing->finalPrice;
            $lineVat   = $itemLogisticsWeight * $pricing->vatAmount;

            $subtotal += $lineTotal;
            $totalVat += $lineVat;
            $totalDiscount += ($pricing->variantDiscountAmount * $itemLogisticsWeight);

            $itemsData[$item->id] = [
                'weight'      => $itemLogisticsWeight,
                'quantity'    => $itemSalesQuantity,
                'unit_name'   => $primaryUnitName ?? 'واحد',
                'unit_price'  => $pricing->finalPrice,
                'total_price' => $lineTotal,
            ];
        }

        $shippingCost = 0.0;
        $finalAmount = max(0, $subtotal + $totalVat + $shippingCost - $totalDiscount);
        $commissions = $this->calculateCommissions($user, $data->beneficiaryUserId, $subtotal);

        return [
            'subtotal'       => $subtotal,
            'vat_amount'     => $totalVat,
            'total_discount' => $totalDiscount,
            'shipping_cost'  => $shippingCost,
            'final_amount'   => $finalAmount,
            'total_weight'   => $totalWeight,
            'commissions'    => $commissions,
            'items_data'     => $itemsData,
        ];
    }

    /**
     * محاسبه سهم‌ها با استفاده از کش برای آیدی نقش‌ها
     */
    private function calculateCommissions(User $placer, ?int $beneficiaryId, float $subtotal): array
    {
        $fees = ['engineer' => 0, 'contractor' => 0];
        if (!$beneficiaryId || $beneficiaryId === $placer->id) return $fees;

        $beneficiary = User::find($beneficiaryId);
        if (!$beneficiary) return $fees;

        $badgeCode = $beneficiary->badge?->code ?? 'pioneer';
        $calculated = $this->settlementCalculator->calculateCommissions($subtotal, $badgeCode);
        $amount = $calculated['CO'] ?? 0;

        $engineerTypeId = Cache::remember('user_type_id_engineer', 86400, function () {
            return UserType::where('slug', 'engineer')->value('id');
        });

        $contractorTypeId = Cache::remember('user_type_id_contractor', 86400, function () {
            return UserType::where('slug', 'contractor')->value('id');
        });

        // بررسی نقش‌ها با آیدی‌های کش شده
        if ($beneficiary->hasRole('engineer') || $beneficiary->user_type_id === $engineerTypeId) {
            $fees['engineer'] = $amount;
        } elseif ($beneficiary->hasRole('contractor') || $beneficiary->user_type_id === $contractorTypeId) {
            $fees['contractor'] = $amount;
        }

        return $fees;
    }

    private function createOrderRecord(User $user, PlaceOrderData $data, array $fins): Order
    {
        return Order::create([
            'user_id'             => $user->id,
            'placer_user_id'      => $user->id,
            'beneficiary_user_id' => $data->beneficiaryUserId ?? $user->id,
            'address_id'          => $data->addressId,
            'shipping_method_id'  => $data->shippingMethodId ?? 1,
            'order_number'        => 'EL-' . strtoupper(Str::random(10)),
            'status'              => Order::STATUS_PENDING,
            'payment_status'      => Order::PAYMENT_PENDING,

            'subtotal'            => $fins['subtotal'],
            'discount_amount'     => $fins['total_discount'],
            'vat_amount'          => $fins['vat_amount'],
            'shipping_cost'       => $fins['shipping_cost'],
            'final_amount'        => $fins['final_amount'],

            'weight_before_scale' => $fins['total_weight'],

            'engineer_fee_amount'           => $fins['commissions']['engineer'],
            'contractor_fee_amount'         => $fins['commissions']['contractor'],
            'engineer_fee_payment_status'   => $fins['commissions']['engineer'] > 0 ? 'pending' : null,
            'contractor_fee_payment_status' => $fins['commissions']['contractor'] > 0 ? 'pending' : null,

            'reservation_expires_at' => now()->addHours(2),
            'notes'                  => $data->description,
        ]);
    }

    private function createOrderItems(Order $order, Cart $cart, array $itemsData): void
    {
        foreach ($cart->items as $item) {
            $supplierId = $item->factory->supplier_user_id ?? 1;

            $calc = $itemsData[$item->id] ?? [
                'weight' => 0,
                'quantity' => 0,
                'unit_name' => '',
                'unit_price' => 0,
                'total_price' => 0
            ];

            OrderItem::create([
                'order_id'           => $order->id,
                'product_variant_id' => $item->product_variant_id,
                'supplier_user_id'   => $supplierId,
                'product_name'       => $item->productVariant->product->name ?? 'Unknown',
                'variant_sku'        => $item->productVariant->sku,

                'quantity_details'   => $item->quantities,
                'quantity'           => $calc['quantity'],
                'weight'             => $calc['weight'],
                'unit_name'          => $calc['unit_name'],

                'unit_price'         => $calc['unit_price'],
                'total_price'        => $calc['total_price'],
            ]);
        }
    }

    private function clearCart(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->delete();
    }
}
