<?php

namespace App\Actions\Order;

use App\DataTransferObjects\Order\PlaceOrderData;
use App\Events\Order\OrderPlaced;
use App\Exceptions\Order\CartIsEmptyException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit;
use App\Models\PriceFilterCategory;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserType;
use App\Services\DocumentSequenceService;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\InventoryService;
use App\Services\PriceCalculationService;
use App\Services\PricingRoleResolver;
use App\Services\Shipping\ShippingCostCalculator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PlaceOrderAction
{
    public function __construct(
        private readonly PriceCalculationService $priceService,
        private readonly PricingRoleResolver $roleResolver,
        private readonly InventoryService $inventoryService,
        private readonly SettlementCalculatorService $settlementCalculator,
        private readonly DocumentSequenceService $sequenceService,
        private readonly ShippingCostCalculator $shippingCalculator,
    ) {}

    public function execute(User $user, PlaceOrderData $data): Order
    {
        return DB::transaction(function () use ($user, $data) {

            // ۱. خواندن و قفل کردن سبد خرید دقیقاً داخل تراکنش
            $cart = $this->getCartOrFail($user);

            // ۲. محاسبه مبالغ
            $financials = $this->calculateOrderFinancials($cart, $user, $data);

            // ۳. ایجاد سفارش + مرسوله‌ها (هر مبدأ→مقصد یک مرسوله) + اقلام
            $order = $this->createOrderRecord($user, $data, $financials, $cart);
            $shipmentMap = $this->createShipments($order, $financials['shipments']);
            $this->createOrderItems($order, $cart, $financials['items_data'], $shipmentMap);

            // ۴. کسر از انبار
            $this->inventoryService->deductStockForOrder($order);

            // ۵. پاکسازی سبد خرید
            $this->clearCart($cart);

            OrderPlaced::dispatch($order);

            return $order;
        });
    }

    private function getCartOrFail(User $user): Cart
    {
        // گام یک: قفل گرفتن روی سبد
        $cart = Cart::where(Cart::USER_ID, $user->id)
            ->lockForUpdate()
            ->latest('updated_at')
            ->first();

        if (! $cart) {
            throw new CartIsEmptyException(__('orders.errors.cart_empty'));
        }

        // گام دو: قفل گرفتن روی آیتم‌ها به طور جداگانه
        $items = CartItem::where('cart_id', $cart->id)
            ->lockForUpdate()
            ->with([
                'productVariant.product',
                'factory',
                'factoryLocation',
            ])
            ->get();

        if ($items->isEmpty()) {
            throw new CartIsEmptyException(__('orders.errors.cart_empty'));
        }

        $cart->load([
            'customerType',
            'badge',
            'priceFilterCategories',
        ]);

        // گام چهار: ست کردن آیتم‌ها روی سبد
        $cart->setRelation('items', $items);

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

        $orderUnits = Cache::remember('order_units_all', 3600, fn () => OrderUnit::all());

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

            $itemLogisticsWeight = 0.0;
            $itemSalesQuantity = 0.0;
            $primaryUnitName = null;

            $quantities = is_string($item->quantities) ? json_decode($item->quantities, true) : $item->quantities;
            if (! is_array($quantities)) {
                $quantities = [];
            }

            if (! empty($quantities)) {
                foreach ($quantities as $unitId => $qty) {
                    $qty = (float) $qty;
                    $itemSalesQuantity += $qty;

                    $unitModel = $orderUnits->where('id', $unitId)->first();
                    if (! $primaryUnitName && $unitModel) {
                        $primaryUnitName = $unitModel->name;
                    }

                    if ($unitModel) {
                        $itemLogisticsWeight += $qty * (float) $unitModel->amount;
                    } else {
                        $variantWeight = (float) ($item->productVariant->weight ?? 1.0);
                        if ($variantWeight <= 0) {
                            $variantWeight = 1.0;
                        }
                        $itemLogisticsWeight += $qty * $variantWeight;
                    }
                }
            } else {
                $simpleQty = (float) ($item->quantity ?? 1);
                $itemSalesQuantity = $simpleQty;
                $primaryUnitName = 'عدد';
                $variantWeight = (float) ($item->productVariant->weight ?? 1.0);
                if ($variantWeight <= 0) {
                    $variantWeight = 1.0;
                }
                $itemLogisticsWeight = $simpleQty * $variantWeight;
            }

            if ($itemLogisticsWeight <= 0) {
                $itemLogisticsWeight = 0.001;
            }

            $totalWeight += $itemLogisticsWeight;

            // محاسبه قیمت‌های این ردیف
            $lineTotal = $itemLogisticsWeight * $pricing->finalPrice;
            $lineVat = $itemLogisticsWeight * $pricing->vatAmount;

            $subtotal += $lineTotal;
            $totalVat += $lineVat;
            $totalDiscount += ($pricing->variantDiscountAmount * $itemLogisticsWeight);

            $itemsData[$item->id] = [
                'weight' => $itemLogisticsWeight,
                'quantity' => $itemSalesQuantity,
                'unit_name' => $primaryUnitName ?? 'واحد',
                'unit_price' => $pricing->finalPrice,
                'total_price' => $lineTotal,
            ];
        }

        // هزینه حمل — هر قلم بر اساس کارخانه‌ی مبدأ و مقصدِ خودش گروه‌بندی (مرسوله) و قیمت‌گذاری
        // می‌شود. مقصدِ هر قلم: آدرسِ خودِ قلم (سبد چندمقصدی) و در نبود آن، آدرسِ اصلی سفارش.
        $primaryAddressId = $data->addressId;

        $neededAddressIds = [];
        foreach ($cart->items as $item) {
            $addrId = $item->address_id ?: $primaryAddressId;
            if ($addrId) {
                $neededAddressIds[(int) $addrId] = true;
            }
        }
        $addresses = Address::whereIn(Address::ID, array_keys($neededAddressIds))->get()->keyBy(Address::ID);

        $shippingLines = [];
        foreach ($cart->items as $item) {
            $addrId = $item->address_id ?: $primaryAddressId;
            $address = $addrId ? $addresses->get((int) $addrId) : null;

            $shippingLines[$item->id] = [
                'factory_id' => $item->factory_id ? (int) $item->factory_id : null,
                'factory_location_id' => $item->factory_location_id ? (int) $item->factory_location_id : null,
                'address_id' => $addrId ? (int) $addrId : null,
                'destination_province_id' => $address?->{Address::PROVINCE_ID} ? (int) $address->{Address::PROVINCE_ID} : null,
                'destination_city_id' => $address?->{Address::CITY_ID} ? (int) $address->{Address::CITY_ID} : null,
                'weight' => (float) ($itemsData[$item->id]['weight'] ?? 0),
            ];
        }

        $shippingQuote = $this->shippingCalculator->quote($shippingLines);

        $shippingCost = $shippingQuote->total;
        foreach ($itemsData as $itemId => $line) {
            $itemsData[$itemId]['shipping_cost'] = (float) ($shippingQuote->perLine[$itemId] ?? 0.0);
        }

        $finalAmount = max(0, $subtotal + $totalVat + $shippingCost - $totalDiscount);
        $commissions = $this->calculateCommissions($user, $data->beneficiaryUserId, $subtotal);

        return [
            'subtotal' => $subtotal,
            'vat_amount' => $totalVat,
            'total_discount' => $totalDiscount,
            'shipping_cost' => $shippingCost,
            'final_amount' => $finalAmount,
            'total_weight' => $totalWeight,
            'commissions' => $commissions,
            'items_data' => $itemsData,
            'shipments' => $shippingQuote->shipments,
        ];
    }

    private function calculateCommissions(User $placer, ?int $beneficiaryId, float $subtotal): array
    {
        $fees = ['engineer' => 0, 'contractor' => 0];

        if (! $beneficiaryId || $beneficiaryId === $placer->id) {
            return $fees;
        }

        $beneficiary = User::find($beneficiaryId);
        if (! $beneficiary) {
            return $fees;
        }

        $badgeCode = $beneficiary->badge?->code ?? 'pioneer';
        $calculated = $this->settlementCalculator->calculateCommissions($subtotal, $badgeCode);
        $amount = $calculated['CO'] ?? 0;

        $engineerTypeId = Cache::remember('user_type_id_engineer', 86400, fn () => UserType::where('slug', 'engineer')->value('id'));
        $contractorTypeId = Cache::remember('user_type_id_contractor', 86400, fn () => UserType::where('slug', 'contractor')->value('id'));

        if ($beneficiary->hasRole('engineer') || $beneficiary->{User::TYPE_ID} === $engineerTypeId) {
            $fees['engineer'] = $amount;
        } elseif ($beneficiary->hasRole('contractor') || $beneficiary->{User::TYPE_ID} === $contractorTypeId) {
            $fees['contractor'] = $amount;
        }

        return $fees;
    }

    private function createOrderRecord(User $user, PlaceOrderData $data, array $fins, Cart $cart): Order
    {
        // ۱. تشخیص کانتکست (Context) کالا بر اساس اولین آیتم سبد خرید
        // می‌توانید منطق شرط را بر اساس ساختار دیتابیس خودتان (مثلاً نام دسته‌بندی) تنظیم کنید
        $firstItemCategory = $cart->items->first()->productVariant->product->category_code ?? '';
        $context = (strtoupper($firstItemCategory) === 'BRICK')
            ? DocumentSequenceService::CTX_BRICK
            : DocumentSequenceService::CTX_REBAR;

        // ۲. تولید شماره سفارش هوشمند، یکتا و استاندارد (مثال: EZ-ORD-RB-140502-70300100)
        $orderNumber = $this->sequenceService->generate(
            DocumentSequenceService::TYPE_ORDER,
            $context,
            Order::class,
            Order::ORDER_NUMBER
        );

        // ۳. ثبت نهایی در دیتابیس
        return Order::create([
            Order::PLACER_USER_ID => $user->id,
            Order::BENEFICIARY_USER_ID => $data->beneficiaryUserId ?? $user->id,
            Order::ADDRESS_ID => $data->addressId,
            'shipping_method_id' => $data->shippingMethodId ?? 1,
            Order::ORDER_NUMBER => $orderNumber, // استفاده از شماره تولید شده
            Order::STATUS => Order::STATUS_PENDING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,

            Order::SUBTOTAL => $fins['subtotal'],
            Order::DISCOUNT_AMOUNT => $fins['total_discount'],
            Order::VAT_AMOUNT => $fins['vat_amount'],
            Order::SHIPPING_COST => $fins['shipping_cost'],
            Order::FINAL_AMOUNT => $fins['final_amount'],
            Order::WEIGHT_BEFORE_SCALE => $fins['total_weight'],

            Order::ENGINEER_FEE_AMOUNT => $fins['commissions']['engineer'],
            Order::CONTRACTOR_FEE_AMOUNT => $fins['commissions']['contractor'],
            Order::ENGINEER_FEE_PAYMENT_STATUS => $fins['commissions']['engineer'] > 0 ? Order::FEE_PAYMENT_PENDING : null,
            Order::CONTRACTOR_FEE_PAYMENT_STATUS => $fins['commissions']['contractor'] > 0 ? Order::FEE_PAYMENT_PENDING : null,

            Order::RESERVATION_EXPIRES_AT => now()->addHours((int) config('financial.pending_order_ttl_hours', 2)),
            Order::NOTES => $data->description,
        ]);
    }

    /**
     * Create one Shipment per (origin → destination) leg and return a map of
     * cart item id → shipment id so order items can be linked to their shipment.
     *
     * @param  \App\DataTransferObjects\Shipping\ShipmentGroup[]  $shipments
     * @return array<int|string, int>
     */
    private function createShipments(Order $order, array $shipments): array
    {
        $map = [];

        foreach ($shipments as $group) {
            $shipment = Shipment::create([
                Shipment::ORDER_ID => $order->id,
                Shipment::FACTORY_ID => $group->factoryId,
                Shipment::FACTORY_LOCATION_ID => $group->factoryLocationId,
                Shipment::ADDRESS_ID => $group->addressId,
                Shipment::DESTINATION_PROVINCE_ID => $group->destinationProvinceId,
                Shipment::DESTINATION_CITY_ID => $group->destinationCityId,
                Shipment::WEIGHT => $group->weight,
                Shipment::SHIPPING_COST => $group->cost,
                Shipment::STATUS => Shipment::STATUS_PENDING,
            ]);

            foreach ($group->lineKeys as $cartItemId) {
                $map[$cartItemId] = $shipment->id;
            }
        }

        return $map;
    }

    private function createOrderItems(Order $order, Cart $cart, array $itemsData, array $shipmentMap): void
    {
        foreach ($cart->items as $item) {
            // An order line must map to a real supplier — the supplier wallet (WS) is
            // settled by this ID. Defaulting to the system user (the old `?? 1`) would
            // misattribute payouts, so surface the data problem instead of hiding it.
            $supplierId = $item->factory?->supplier_user_id;
            if (! $supplierId) {
                throw new \RuntimeException(
                    "Cart item {$item->id} has no factory supplier_user_id; cannot place order."
                );
            }

            $calc = $itemsData[$item->id] ?? [
                'weight' => 0,
                'quantity' => 0,
                'unit_name' => '',
                'unit_price' => 0,
                'total_price' => 0,
                'shipping_cost' => 0,
            ];

            OrderItem::create([
                OrderItem::ORDER_ID => $order->id,
                OrderItem::SHIPMENT_ID => $shipmentMap[$item->id] ?? null,
                'product_variant_id' => $item->product_variant_id,
                'supplier_user_id' => $supplierId,
                // Preserve the line's origin so per-factory freight can be reconstructed.
                OrderItem::FACTORY_ID => $item->factory_id,
                OrderItem::FACTORY_LOCATION_ID => $item->factory_location_id,
                'product_name' => $item->productVariant->product->name ?? 'Unknown',
                'variant_sku' => $item->productVariant->sku,

                'quantity_details' => $item->quantities,
                'quantity' => $calc['quantity'],
                'weight' => $calc['weight'],
                'unit_name' => $calc['unit_name'],

                'unit_price' => $calc['unit_price'],
                'total_price' => $calc['total_price'],
                OrderItem::SHIPPING_COST => $calc['shipping_cost'] ?? 0,
            ]);
        }
    }

    private function clearCart(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->delete();
    }
}
