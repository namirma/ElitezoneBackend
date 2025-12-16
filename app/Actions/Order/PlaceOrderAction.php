<?php

namespace App\Actions\Order;

use App\DataTransferObjects\PlaceOrderData;
use App\DataTransferObjects\Pricing\PriceCalculationResult; // مطمئن شوید فایلش وجود دارد
use App\Events\OrderPlaced;
use App\Exceptions\Order\CartIsEmptyException;
use App\Exceptions\Order\StockUnavailableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit;
use App\Models\PriceFilterCategory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserType;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Services\InventoryService;
use App\Services\PriceCalculationService;
use App\Services\Pricing\CartPricingCalculator;
use App\Services\PricingRoleResolver;
use Illuminate\Support\Collection;
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
        private readonly OrderRepositoryInterface $orderRepo
    ) {}

    public function execute(User $user, PlaceOrderData $data): Order
    {
        $cart = $this->getCartOrFail($user);

        $this->ensureStockIsAvailable($cart);

        $financials = $this->calculateFinancials($cart, $user, $data);

        $order = DB::transaction(function () use ($user, $cart, $data, $financials) {
            $order = $this->createOrderRecord($user, $data, $financials);

            $this->createOrderItems($order, $cart);

            $this->inventoryService->deductStockForOrder($order);

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

        // اصلاح: اکسپشن باید مسیج قبول کند. اگر کلاسش خالی است، متن را به Exception اصلی پاس می‌دهد.
        if (!$cart || $cart->items->isEmpty()) {
            throw new CartIsEmptyException(__('orders.errors.cart_empty'));
        }

        return $cart;
    }

    private function ensureStockIsAvailable(Cart $cart): void
    {
        $unitsMap = Cache::remember('order_units_map', 86400, function () {
            return OrderUnit::all()->keyBy(OrderUnit::ID);
        });

        foreach ($cart->items as $item) {
            $variant = $item->productVariant;
            $requiredBaseQty = 0.0;

            foreach ($item->{CartItem::QUANTITIES} as $unitId => $qty) {
                $unit = $unitsMap->get((int)$unitId);
                if ($unit) {
                    $requiredBaseQty += (float)$qty * (float)$unit->amount;
                }
            }

            if ($variant->stock_quantity < $requiredBaseQty) {
                // اصلاح: ارسال پیام ترجمه شده به اکسپشن
                throw new StockUnavailableException(__('orders.errors.stock_unavailable', ['product' => $variant->name]));
            }
        }
    }

    private function calculateFinancials(Cart $cart, User $user, PlaceOrderData $data): array
    {
        $subtotal = 0.0;
        $totalVat = 0.0;
        $totalItemDiscounts = 0.0;

        $activeCode = $this->roleResolver->resolve($user);
        $priceFilter = PriceFilterCategory::where('code', $activeCode)->first();
        $filterIds = $priceFilter ? [$priceFilter->id] : $cart->priceFilterCategories->pluck('id')->toArray();
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first() ?: $cart->customerType;

        $orderUnitsMap = OrderUnit::all()->keyBy(OrderUnit::ID);

        foreach ($cart->items as $item) {
            /** @var PriceCalculationResult $pricing */
            // اطمینان حاصل کنید که سرویس calculatePrice دقیقاً آبجکت PriceCalculationResult برمی‌گرداند
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

            $itemTotalWeight = $this->calculateItemWeight($item->{CartItem::QUANTITIES}, $orderUnitsMap);

            $unitPrice = $pricing->finalPrice;
            $unitVatAmount = $pricing->vatAmount;

            $lineTotal = $itemTotalWeight * $unitPrice;
            $lineVat = $itemTotalWeight * $unitVatAmount;

            $item->calculated_unit_price = $unitPrice;
            $item->calculated_line_total = $lineTotal;
            $item->calculated_line_vat = $lineVat;

            $subtotal += $lineTotal;
            $totalVat += $lineVat;

            $itemDiscountPerUnit = $pricing->variantDiscountAmount;
            $totalItemDiscounts += $itemDiscountPerUnit * $itemTotalWeight;
        }

        $cartOfferDiscount = $this->calculateCartLevelDiscounts($cart->items, $subtotal, $orderUnitsMap);

        $totalDiscount = $totalItemDiscounts + $cartOfferDiscount;
        $fees = $this->calculateBeneficiaryFees($user, $data->beneficiaryUserId, $subtotal);
        $shippingCost = 0.0;

        $finalAmount = max(0, ($subtotal - $cartOfferDiscount) + $totalVat + $shippingCost);

        return [
            'subtotal'       => $subtotal,
            'vat_amount'     => $totalVat,
            'total_discount' => $totalDiscount,
            'shipping_cost'  => $shippingCost,
            'final_amount'   => $finalAmount,
            'fees'           => $fees,
        ];
    }

    private function calculateItemWeight(array $quantities, Collection $unitsMap): float
    {
        $weight = 0.0;
        foreach ($quantities as $unitId => $qty) {
            if ($unit = $unitsMap->get((int)$unitId)) {
                $weight += (float)$qty * (float)$unit->{OrderUnit::AMOUNT};
            }
        }
        return $weight;
    }

    private function calculateCartLevelDiscounts(Collection $items, float $subtotal, Collection $unitsMap): float
    {
        $applicableOffers = $items->flatMap(function (CartItem $item) use ($unitsMap) {
            $totalWeight = $this->calculateItemWeight($item->{CartItem::QUANTITIES}, $unitsMap);
            return $item->productVariant->product->offers()->current()
                ->where(Offer::MINIMUM_QUANTITY, '<=', $totalWeight)
                ->get();
        })->unique(Offer::ID);

        $discount = 0.0;
        foreach ($applicableOffers as $offer) {
            if ($offer->{Offer::TYPE} === 'percentage') {
                $discount += $subtotal * ($offer->{Offer::VALUE} / 100);
            } elseif ($offer->{Offer::TYPE} === 'fixed_amount') {
                $discount += (float)$offer->{Offer::VALUE};
            }
        }
        return min($discount, $subtotal);
    }

    private function calculateBeneficiaryFees(User $placer, ?int $beneficiaryId, float $subtotal): array
    {
        $fees = ['engineer' => null, 'contractor' => null];
        if (!$beneficiaryId || $beneficiaryId == $placer->id) return $fees;

        $beneficiary = User::find($beneficiaryId);
        if (!$beneficiary) return $fees;

        $typeIds = UserType::whereIn('slug', ['engineer', 'contractor'])->pluck('id', 'slug');

        if (isset($typeIds['engineer']) && $beneficiary->{User::TYPE_ID} == $typeIds['engineer']) {
            $pct = config('elitezon.consultant_fee_percentage', 2.0);
            $fees['engineer'] = ($subtotal * $pct) / 100;
        } elseif (isset($typeIds['contractor']) && $beneficiary->{User::TYPE_ID} == $typeIds['contractor']) {
            $pct = config('elitezon.contractor_fee_percentage', 2.0);
            $fees['contractor'] = ($subtotal * $pct) / 100;
        }
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

            if (!$supplierId) {
                throw new Exception("تامین‌کننده برای کارخانه {$item->factory->name} تعریف نشده است.");
            }

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
