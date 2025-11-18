<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\Factory;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\Address;
use App\Models\CustomerType;
use App\Models\OrderUnit;
use App\Models\Offer;
use App\Models\PriceFilterCategory;
use App\Models\User;
use App\Models\UserType;
use App\Services\PriceCalculationService;
use App\Services\PricingRoleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Resources\OrderResource;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    private ?Collection $orderUnitsMapCache = null;

    public function __construct(
        private readonly PriceCalculationService $priceService,
        private readonly PricingRoleResolver $roleResolver
    ) {}

    private function activeCode(): string
    {
        if (app()->bound('active_price_filter_category_code')) {
            return app('active_price_filter_category_code');
        }
        if (Auth::check()) {
            $user = Auth::user()->loadMissing(['roles', 'employer']);
            return $this->roleResolver->resolve($user);
        }
        return 'employer_without_consultant';
    }

    private function getOrderUnitsMap(): Collection
    {
        if ($this->orderUnitsMapCache === null) {
            $this->orderUnitsMapCache = OrderUnit::all()->keyBy(OrderUnit::ID);
        }
        return $this->orderUnitsMapCache;
    }

    private function collectOffers(Collection $items): Collection
    {
        return $items->flatMap(fn(CartItem $item) =>
            $item->productVariant->product->offers()->current()
                ->where(Offer::MINIMUM_QUANTITY, '<=', array_sum($item->{CartItem::QUANTITIES}))
                ->get()
        )->unique(Offer::ID)->values();
    }

    private function calculateCartLevelOfferDiscountAmount(float $subtotal, Collection $offers): float
    {
        $totalDiscountFromOffers = 0.0;
        foreach ($offers as $offer) {
            /** @var Offer $offer */
            if ($offer->{Offer::TYPE} === 'percentage') {
                $totalDiscountFromOffers += $subtotal * ($offer->{Offer::VALUE} / 100);
            } elseif ($offer->{Offer::TYPE} === 'fixed_amount') {
                $totalDiscountFromOffers += (float)$offer->{Offer::VALUE};
            }
        }
        return min($totalDiscountFromOffers, $subtotal > 0 ? $subtotal : 0);
    }

    public function placeOrder(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            Order::BENEFICIARY_USER_ID => 'nullable|exists:users,id',
            Order::ADDRESS_ID => [
                'nullable',
                Rule::exists('addresses', Address::ID)->where(Address::USER_ID, $user->id)
            ],
        ]);

        $cart = Cart::where(Cart::USER_ID, $user->id)->with([
            'items.productVariant.product.offers',
            'items.factory',
            'items.factoryLocation',
            'customerType',
            'badge',
            'priceFilterCategories'
        ])->first();

        if (!$cart || $cart->items->isEmpty()) {
            return error(__('cart.cart_is_empty'), [], 400);
        }

        $supplierMapping = [];
        foreach ($cart->items as $item) {
            $item->loadMissing('factory');
            if (!$item->factory || !$item->factory->{Factory::SUPPLIER_USER_ID}) {
                $factoryName = $item->factory->{Factory::NAME} ?? $item->{CartItem::FACTORY_ID};
                return error(__('checkout.supplier_not_found_for_factory', ['factory' => $factoryName]), [], 400);
            }
            $supplierMapping[$item->id] = $item->factory->{Factory::SUPPLIER_USER_ID};
        }

        $subtotalAfterVariantDiscounts = 0.0;
        $totalVatAmount = 0.0;
        $totalAppliedVariantDiscount = 0.0;
        $orderUnitsMap = $this->getOrderUnitsMap();
        $activeCode = $this->activeCode();
        $pfId = PriceFilterCategory::where(PriceFilterCategory::CODE, $activeCode)->value('id');
        $filterIds = $pfId ? [$pfId] : $cart->priceFilterCategories->pluck(PriceFilterCategory::ID)->all();
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first() ?: $cart->customerType;
        $badge = $cart->badge;

        foreach ($cart->items as $item) {
            $pricingDetails = $this->priceService->calculatePrice(
                $item->productVariant,
                $item->factory,
                $item->factoryLocation,
                $customerType,
                $badge,
                $filterIds,
                true
            );

            $itemTotalWeight = 0;
            foreach ($item->{CartItem::QUANTITIES} as $unitId => $qty) {
                /** @var OrderUnit|null $unit */
                $unit = $orderUnitsMap->get((int)$unitId);
                if ($unit) {
                    $itemTotalWeight += (float)$qty * $unit->{OrderUnit::AMOUNT};
                }
            }

            $variantDiscountAmountFromService = isset($pricingDetails['variant_discount_amount']) ? (float)$pricingDetails['variant_discount_amount'] : 0.0;
            $totalAppliedVariantDiscount += $variantDiscountAmountFromService * $itemTotalWeight;

            $itemFinalPrice = $itemTotalWeight * (float)$pricingDetails['final_price'];
            $itemVatAmount = 0.0;
            if (isset($pricingDetails['vat_rate']) && (float)$pricingDetails['vat_rate'] > 0) {
                $itemVatAmount = $itemFinalPrice * ((float)$pricingDetails['vat_rate'] / 100);
            }

            $subtotalAfterVariantDiscounts += $itemFinalPrice;
            $totalVatAmount += $itemVatAmount;

            $item->{CartItem::PRICE} = $pricingDetails['final_price'];
            $item->{CartItem::FINAL_PRICE} = $itemFinalPrice;
            $item->{CartItem::VAT_AMOUNT} = $itemVatAmount;
        }

        $offers = $this->collectOffers($cart->items);
        $cartLevelOfferDiscount = $this->calculateCartLevelOfferDiscountAmount($subtotalAfterVariantDiscounts, $offers);

        $totalDiscount = $totalAppliedVariantDiscount + $cartLevelOfferDiscount;
        $shippingCost = 0.0;

        $finalAmount = ($subtotalAfterVariantDiscounts - $cartLevelOfferDiscount) + $totalVatAmount + $shippingCost;

        $beneficiaryUserId = $validated[Order::BENEFICIARY_USER_ID] ?? null;
        $placerUserId = $user->id;

        $engineerFeeAmount = null;
        $contractorFeeAmount = null;

        if ($beneficiaryUserId && $beneficiaryUserId != $placerUserId) {
            $beneficiaryUser = User::find($beneficiaryUserId);

            if ($beneficiaryUser) {
                static $consultantTypeIds = null;
                if ($consultantTypeIds === null) {
                    $consultantTypeIds = UserType::whereIn('slug', ['engineer', 'contractor'])
                                                 ->pluck('id', 'slug');
                }

                $engineerTypeId = $consultantTypeIds->get('engineer');
                $contractorTypeId = $consultantTypeIds->get('contractor');

                if ($engineerTypeId && $beneficiaryUser->{User::TYPE_ID} == $engineerTypeId) {
                    $feePercentage = config('elitezon.consultant_fee_percentage', 2.0);
                    $engineerFeeAmount = ($subtotalAfterVariantDiscounts * $feePercentage) / 100;
                } elseif ($contractorTypeId && $beneficiaryUser->{User::TYPE_ID} == $contractorTypeId) {
                    $feePercentage = config('elitezon.contractor_fee_percentage', 2.0);
                    $contractorFeeAmount = ($subtotalAfterVariantDiscounts * $feePercentage) / 100;
                }
            }
        }

        $order = DB::transaction(function () use (
            $user, $cart, $validated, $supplierMapping,
            $finalAmount, $subtotalAfterVariantDiscounts, $totalVatAmount, $totalDiscount, $shippingCost,
            $engineerFeeAmount, $contractorFeeAmount
        ) {
            $order = Order::create([
                Order::PLACER_USER_ID => $user->id,
                Order::BENEFICIARY_USER_ID => $validated[Order::BENEFICIARY_USER_ID] ?? $user->id,
                Order::ADDRESS_ID => $validated[Order::ADDRESS_ID] ?? null,
                Order::ORDER_NUMBER => 'EL-' . strtoupper(Str::random(10)),
                Order::STATUS => Order::STATUS_PENDING,
                Order::FINAL_AMOUNT => $finalAmount,
                Order::SUBTOTAL => $subtotalAfterVariantDiscounts,
                Order::VAT_AMOUNT => $totalVatAmount,
                Order::DISCOUNT_AMOUNT => $totalDiscount,
                Order::SHIPPING_COST => $shippingCost,
                Order::RESERVATION_EXPIRES_AT => now()->addHours(2),
                Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
                Order::ENGINEER_FEE_AMOUNT => $engineerFeeAmount,
                Order::CONTRACTOR_FEE_AMOUNT => $contractorFeeAmount,
                Order::EXPERT_FEE_AMOUNT => null,
                Order::ENGINEER_FEE_PAYMENT_STATUS => $engineerFeeAmount ? Order::FEE_PAYMENT_PENDING : null,
                Order::CONTRACTOR_FEE_PAYMENT_STATUS => $contractorFeeAmount ? Order::FEE_PAYMENT_PENDING : null,
                Order::EXPERT_FEE_PAYMENT_STATUS => null,
            ]);

            foreach ($cart->items as $item) {
                OrderItem::create([
                    OrderItem::ORDER_ID => $order->id,
                    OrderItem::PRODUCT_VARIANT_ID => $item->{CartItem::PRODUCT_VARIANT_ID},
                    OrderItem::SUPPLIER_USER_ID => $supplierMapping[$item->id],
                    OrderItem::PRODUCT_NAME => $item->productVariant->{ProductVariant::NAME},
                    OrderItem::VARIANT_SKU => $item->productVariant->{ProductVariant::SKU},
                    OrderItem::QUANTITY_DETAILS => $item->{CartItem::QUANTITIES},
                    OrderItem::UNIT_PRICE => $item->{CartItem::PRICE},
                    OrderItem::TOTAL_PRICE => $item->{CartItem::FINAL_PRICE},
                ]);
            }

            $cart->items()->delete();
            $cart->delete();

            return $order;
        });

        return success(new OrderResource($order->load('items')), __('checkout.order_placed_reserved'));
    }
}
