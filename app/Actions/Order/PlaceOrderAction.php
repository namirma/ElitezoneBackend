<?php

namespace App\Actions\Order;

use App\DataTransferObjects\PlaceOrderData;
use App\Events\OrderPlaced;
use App\Exceptions\Order\CartIsEmptyException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit;
use App\Models\PriceFilterCategory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserType;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\InventoryService;
use App\Services\PriceCalculationService;
use App\Services\Pricing\CartPricingCalculator;
use App\Services\PricingRoleResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Exception;

class PlaceOrderAction
{
    public function __construct(
        private readonly CartPricingCalculator $cartPricing,
        private readonly PriceCalculationService $priceService,
        private readonly PricingRoleResolver $roleResolver,
        private readonly InventoryService $inventoryService,
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly SettlementCalculatorService $settlementCalculator
    ) {}

    public function execute(User $user, PlaceOrderData $data): Order
    {
        $cart = $this->getCartOrFail($user);

        // محاسبه مالی و وزنی
        $financials = $this->calculateFinancials($cart, $user, $data);

        $order = DB::transaction(function () use ($user, $cart, $data, $financials) {
            // ساخت سفارش
            $order = $this->createOrderRecord($user, $data, $financials);

            // ثبت آیتم‌ها
            $this->createOrderItems($order, $cart);

            // کسر موجودی (با استفاده از متد ساده شده در InventoryService)
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
        $cart = Cart::where(Cart::USER_ID, $user->id)
            ->with([
                'items.productVariant.product.offers',
                'items.factory',
                'items.factoryLocation',
                'customerType',
                'badge',
                'priceFilterCategories'
            ])->first();

        if (!$cart || $cart->items->isEmpty()) {
            throw new CartIsEmptyException(__('orders.errors.cart_empty'));
        }
        return $cart;
    }

    private function calculateFinancials(Cart $cart, User $user, PlaceOrderData $data): array
    {
        $subtotal = 0.0;
        $totalVat = 0.0;
        $totalItemDiscounts = 0.0;
        $totalOrderWeight = 0.0; // وزن کل سفارش

        $activeCode = $this->roleResolver->resolve($user);
        $priceFilter = PriceFilterCategory::where('code', $activeCode)->first();
        $filterIds = $priceFilter ? [$priceFilter->id] : $cart->priceFilterCategories->pluck('id')->toArray();
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first() ?: $cart->customerType;

        // کش کردن واحدها برای سرعت
        $orderUnitsMap = Cache::remember('all_order_units', 3600, fn() => OrderUnit::all()->keyBy('id'));

        foreach ($cart->items as $item) {
            $pricing = $this->priceService->calculatePrice(
                variant: $item->productVariant,
                factory: $item->factory,
                location: $item->factoryLocation,
                customerType: $customerType,
                badge: $cart->badge,
                filterCategoryIds: $filterIds,
                withVAT: true,
                filterCategoryCode: $activeCode
            );

            // ====================================================
            // منطق ساده محاسبه وزن: (تعداد × مقدار واحد)
            // ====================================================
            $itemTotalWeight = 0.0;
            foreach ($item->{CartItem::QUANTITIES} as $unitId => $qty) {
                $qty = (float) $qty;
                $unit = $orderUnitsMap->get($unitId);
                if ($unit) {
                    $itemTotalWeight += $qty * (float)$unit->amount;
                } else {
                    // فال‌بک: اگر واحد پیدا نشد، خود تعداد
                    $itemTotalWeight += $qty;
                }
            }
            $totalOrderWeight += $itemTotalWeight;
            // ====================================================

            $lineTotal = $itemTotalWeight * $pricing->finalPrice;
            $lineVat = $itemTotalWeight * $pricing->vatAmount;

            $item->calculated_unit_price = $pricing->finalPrice;
            $item->calculated_line_total = $lineTotal;
            $item->calculated_line_vat = $lineVat;

            $subtotal += $lineTotal;
            $totalVat += $lineVat;
            $totalItemDiscounts += $pricing->variantDiscountAmount * $itemTotalWeight;
        }

        // محاسبه کارمزدها با استفاده از سرویس
        $fees = $this->calculateCommissions($user, $data->beneficiaryUserId, $subtotal);

        $cartOfferDiscount = 0.0;
        $shippingCost = 0.0;
        $totalDiscount = $totalItemDiscounts + $cartOfferDiscount;
        $finalAmount = max(0, ($subtotal - $cartOfferDiscount) + $totalVat + $shippingCost);

        return [
            'subtotal'       => $subtotal,
            'vat_amount'     => $totalVat,
            'total_discount' => $totalDiscount,
            'shipping_cost'  => $shippingCost,
            'final_amount'   => $finalAmount,
            'fees'           => $fees,
            'total_weight'   => $totalOrderWeight,
        ];
    }

    /**
     * محاسبه و تخصیص کارمزد مشاور
     */
    private function calculateCommissions(User $placer, ?int $beneficiaryId, float $subtotal): array
    {
        $fees = [
            'engineer' => 0,
            'contractor' => 0,
            'owner_discount' => 0, // اختیاری: اگر فیلد دارید
            'saze_share' => 0      // اختیاری
        ];

        // اگر مشاور وجود ندارد یا کاربر خودش خرید کرده
        if (!$beneficiaryId || $beneficiaryId == $placer->id) {
            return $fees;
        }

        $beneficiary = User::find($beneficiaryId);
        if (!$beneficiary) return $fees;

        // 1. دریافت نشان مشاور
        $badgeCode = $beneficiary->badge ? $beneficiary->badge->code : 'pioneer';

        // 2. محاسبه مبالغ از سرویس
        $commissions = $this->settlementCalculator->calculateCommissions($subtotal, $badgeCode);
        $coAmount = $commissions['CO'];

        // 3. تشخیص نوع مشاور (مهندس یا پیمانکار) برای ذخیره در ستون درست
        $engineerTypeId = UserType::where('slug', 'engineer')->value('id');
        $contractorTypeId = UserType::where('slug', 'contractor')->value('id');

        if ($beneficiary->user_type_id == $engineerTypeId) {
            $fees['engineer'] = $coAmount;
        } elseif ($beneficiary->user_type_id == $contractorTypeId) {
            $fees['contractor'] = $coAmount;
        }

        // ذخیره سایر سهم‌ها (جهت اطمینان یا دیباگ)
        $fees['owner_discount'] = $commissions['OW'];
        $fees['saze_share'] = $commissions['SSG'];

        return $fees;
    }

    private function createOrderRecord(User $user, PlaceOrderData $data, array $fins): Order
    {
        return $this->orderRepo->create([
            Order::PLACER_USER_ID => $user->id,
            Order::BENEFICIARY_USER_ID => $data->beneficiaryUserId ?? $user->id,
            Order::ADDRESS_ID => $data->addressId,
            Order::ORDER_NUMBER => 'EL-' . strtoupper(Str::random(10)),
            Order::STATUS => Order::STATUS_PENDING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,

            Order::SUBTOTAL => $fins['subtotal'],
            Order::DISCOUNT_AMOUNT => $fins['total_discount'],
            Order::VAT_AMOUNT => $fins['vat_amount'],
            Order::SHIPPING_COST => $fins['shipping_cost'],
            Order::FINAL_AMOUNT => $fins['final_amount'],

            // ذخیره وزن محاسبه شده
            'weight_before_scale' => $fins['total_weight'],

            // ذخیره کارمزدها
            Order::ENGINEER_FEE_AMOUNT => $fins['fees']['engineer'],
            Order::CONTRACTOR_FEE_AMOUNT => $fins['fees']['contractor'],

            Order::ENGINEER_FEE_PAYMENT_STATUS => $fins['fees']['engineer'] ? Order::FEE_PAYMENT_PENDING : null,
            Order::CONTRACTOR_FEE_PAYMENT_STATUS => $fins['fees']['contractor'] ? Order::FEE_PAYMENT_PENDING : null,

            Order::RESERVATION_EXPIRES_AT => now()->addHours(2),
            Order::NOTES => $data->description,
        ]);
    }

    private function createOrderItems(Order $order, Cart $cart): void
    {
        foreach ($cart->items as $item) {
            $supplierId = $item->factory->{Factory::SUPPLIER_USER_ID} ?? null;
            if (!$supplierId) $supplierId = 1;

            OrderItem::create([
                OrderItem::ORDER_ID => $order->id,
                OrderItem::PRODUCT_VARIANT_ID => $item->{CartItem::PRODUCT_VARIANT_ID},
                OrderItem::SUPPLIER_USER_ID => $supplierId,
                OrderItem::PRODUCT_NAME => $item->productVariant->{ProductVariant::NAME},
                OrderItem::VARIANT_SKU => $item->productVariant->{ProductVariant::SKU},
                OrderItem::QUANTITY_DETAILS => $item->{CartItem::QUANTITIES},
                OrderItem::UNIT_PRICE => $item->calculated_unit_price,
                OrderItem::TOTAL_PRICE => $item->calculated_line_total,
            ]);
        }
    }

    private function clearCart(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->delete();
    }
}
