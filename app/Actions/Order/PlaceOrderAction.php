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
use App\Models\ShippingMethod;
use App\Services\DocumentSequenceService;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\InventoryService;
use App\Services\PriceCalculationService;
use App\Services\PricingRoleResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlaceOrderAction
{
    public function __construct(
        private readonly PriceCalculationService $priceService,
        private readonly PricingRoleResolver $roleResolver,
        private readonly InventoryService $inventoryService,
        private readonly SettlementCalculatorService $settlementCalculator,
        private readonly DocumentSequenceService $sequenceService
    ) {}

    public function execute(User $user, PlaceOrderData $data): Order
    {
        $cart = $this->getCartOrFail($user);

        $financials = $this->calculateOrderFinancials($cart, $user, $data);

        $order = DB::transaction(function () use ($user, $cart, $data, $financials) {

            $order = $this->createOrderRecord($user, $data, $financials, $cart);

            $this->createOrderItems($order, $cart, $financials['items_data']);

            $this->inventoryService->deductStockForOrder($order);

            // پاکسازی سبد خرید
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
            ->where(Cart::USER_ID, $user->id)
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

            $itemLogisticsWeight = 0.0;
            $itemSalesQuantity = 0.0;
            $primaryUnitName = null;

            $quantities = is_string($item->quantities) ? json_decode($item->quantities, true) : $item->quantities;
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
                        $variantWeight = (float) ($item->productVariant->weight ?? 1.0);
                        if ($variantWeight <= 0) $variantWeight = 1.0;
                        $itemLogisticsWeight += $qty * $variantWeight;
                    }
                }
            } else {
                $simpleQty = (float) ($item->quantity ?? 1);
                $itemSalesQuantity = $simpleQty;
                $primaryUnitName = 'عدد';
                $variantWeight = (float) ($item->productVariant->weight ?? 1.0);
                if ($variantWeight <= 0) $variantWeight = 1.0;
                $itemLogisticsWeight = $simpleQty * $variantWeight;
            }

            if ($itemLogisticsWeight <= 0) $itemLogisticsWeight = 0.001;

            $totalWeight += $itemLogisticsWeight;

            // محاسبه قیمت‌های این ردیف
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

        // هزینه ارسال
        $shippingCost = 0.0;
        if ($data->shippingMethodId) {
            $shippingMethod = ShippingMethod::find($data->shippingMethodId);
            $shippingCost = $shippingMethod ? (float) ($shippingMethod->cost ?? $shippingMethod->price ?? 0.0) : 0.0;
        }

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

    private function calculateCommissions(User $placer, ?int $beneficiaryId, float $subtotal): array
    {
        $fees = ['engineer' => 0, 'contractor' => 0];

        if (!$beneficiaryId || $beneficiaryId === $placer->id) return $fees;

        $beneficiary = User::find($beneficiaryId);
        if (!$beneficiary) return $fees;

        $badgeCode = $beneficiary->badge?->code ?? 'pioneer';
        $calculated = $this->settlementCalculator->calculateCommissions($subtotal, $badgeCode);
        $amount = $calculated['CO'] ?? 0;

        $engineerTypeId = Cache::remember('user_type_id_engineer', 86400, fn() => UserType::where('slug', 'engineer')->value('id'));
        $contractorTypeId = Cache::remember('user_type_id_contractor', 86400, fn() => UserType::where('slug', 'contractor')->value('id'));

        if ($beneficiary->hasRole('engineer') || $beneficiary->user_type_id === $engineerTypeId) {
            $fees['engineer'] = $amount;
        } elseif ($beneficiary->hasRole('contractor') || $beneficiary->user_type_id === $contractorTypeId) {
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
            Order::PLACER_USER_ID                => $user->id,
            Order::BENEFICIARY_USER_ID           => $data->beneficiaryUserId ?? $user->id,
            Order::ADDRESS_ID                    => $data->addressId,
            'shipping_method_id'                 => $data->shippingMethodId ?? 1,
            Order::ORDER_NUMBER                  => $orderNumber, // استفاده از شماره تولید شده
            Order::STATUS                        => Order::STATUS_PENDING,
            Order::PAYMENT_STATUS                => Order::PAYMENT_PENDING,

            Order::SUBTOTAL                      => $fins['subtotal'],
            Order::DISCOUNT_AMOUNT               => $fins['total_discount'],
            Order::VAT_AMOUNT                    => $fins['vat_amount'],
            Order::SHIPPING_COST                 => $fins['shipping_cost'],
            Order::FINAL_AMOUNT                  => $fins['final_amount'],
            Order::WEIGHT_BEFORE_SCALE           => $fins['total_weight'],

            Order::ENGINEER_FEE_AMOUNT           => $fins['commissions']['engineer'],
            Order::CONTRACTOR_FEE_AMOUNT         => $fins['commissions']['contractor'],
            Order::ENGINEER_FEE_PAYMENT_STATUS   => $fins['commissions']['engineer'] > 0 ? Order::FEE_PAYMENT_PENDING : null,
            Order::CONTRACTOR_FEE_PAYMENT_STATUS => $fins['commissions']['contractor'] > 0 ? Order::FEE_PAYMENT_PENDING : null,

            Order::RESERVATION_EXPIRES_AT        => now()->addHours(2),
            Order::NOTES                         => $data->description,
        ]);
    }

    private function createOrderItems(Order $order, Cart $cart, array $itemsData): void
    {
        foreach ($cart->items as $item) {
            $supplierId = $item->factory->supplier_user_id ?? 1;

            $calc = $itemsData[$item->id] ?? [
                'weight'      => 0,
                'quantity'    => 0,
                'unit_name'   => '',
                'unit_price'  => 0,
                'total_price' => 0
            ];

            OrderItem::create([
                OrderItem::ORDER_ID           => $order->id,
                'product_variant_id'          => $item->product_variant_id,
                'supplier_user_id'            => $supplierId,
                'product_name'                => $item->productVariant->product->name ?? 'Unknown',
                'variant_sku'                 => $item->productVariant->sku,

                'quantity_details'            => $item->quantities,
                'quantity'                    => $calc['quantity'],
                'weight'                      => $calc['weight'],
                'unit_name'                   => $calc['unit_name'],

                'unit_price'                  => $calc['unit_price'],
                'total_price'                 => $calc['total_price'],
            ]);
        }
    }

    private function clearCart(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->delete();
    }
}
