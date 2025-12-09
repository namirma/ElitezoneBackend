<?php

namespace App\Actions\Order;

use App\DataTransferObjects\PlaceOrderData;
use App\Events\OrderPlaced;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderUnit;
use App\Models\User;
use App\Models\UserType;
use App\Services\InventoryService;
use App\Services\PriceCalculationService;
use App\Services\PricingRoleResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class PlaceOrderAction
{
    public function __construct(
        private readonly PriceCalculationService $priceService,
        private readonly PricingRoleResolver $roleResolver,
        private readonly InventoryService $inventoryService
    ) {}

    /**
     * اجرای عملیات ثبت سفارش
     */
    public function execute(User $user, PlaceOrderData $data): Order
    {
        // ۱. دریافت و اعتبارسنجی سبد خرید
        $cart = $this->getCart($user);

        if (!$cart || $cart->items->isEmpty()) {
            throw new Exception(__('cart.cart_is_empty'));
        }

        // ۲. اعتبارسنجی انبار قبل از شروع پردازش سنگین (Fail Fast)
        $this->validateStockAvailability($cart);

        // ۳. محاسبات مالی کامل (قیمت، تخفیف، مالیات، کارمزد)
        $totals = $this->calculateOrderTotals($cart, $user, $data);

        // ۴. تراکنش دیتابیس (ثبت سفارش + کسر موجودی + حذف سبد)
        $order = DB::transaction(function () use ($user, $cart, $data, $totals) {

            // الف) ایجاد سفارش
            $order = Order::create([
                Order::PLACER_USER_ID         => $user->id,
                Order::BENEFICIARY_USER_ID    => $data->beneficiaryUserId ?? $user->id,
                Order::ADDRESS_ID             => $data->addressId,
                Order::ORDER_NUMBER           => 'EL-' . strtoupper(Str::random(10)),
                Order::STATUS                 => Order::STATUS_PENDING,
                Order::PAYMENT_STATUS         => Order::PAYMENT_PENDING,

                // مبالغ محاسبه شده
                Order::SUBTOTAL               => $totals['subtotal'],
                Order::DISCOUNT_AMOUNT        => $totals['total_discount'],
                Order::VAT_AMOUNT             => $totals['vat_amount'],
                Order::SHIPPING_COST          => $totals['shipping_cost'],
                Order::FINAL_AMOUNT           => $totals['final_amount'],

                // کارمزدها
                Order::ENGINEER_FEE_AMOUNT    => $totals['fees']['engineer'],
                Order::CONTRACTOR_FEE_AMOUNT  => $totals['fees']['contractor'],
                Order::ENGINEER_FEE_PAYMENT_STATUS => $totals['fees']['engineer'] ? Order::FEE_PAYMENT_PENDING : null,
                Order::CONTRACTOR_FEE_PAYMENT_STATUS => $totals['fees']['contractor'] ? Order::FEE_PAYMENT_PENDING : null,

                Order::RESERVATION_EXPIRES_AT => now()->addHours(2),
                Order::NOTES                  => $data->description,
            ]);

            // ب) ایجاد آیتم‌های سفارش
            foreach ($cart->items as $item) {
                $supplierId = $item->factory->{Factory::SUPPLIER_USER_ID} ?? null;
                if (!$supplierId) {
                    throw new Exception("تامین‌کننده برای کارخانه {$item->factory->name} یافت نشد.");
                }

                OrderItem::create([
                    OrderItem::ORDER_ID           => $order->id,
                    OrderItem::PRODUCT_VARIANT_ID => $item->{CartItem::PRODUCT_VARIANT_ID},
                    OrderItem::SUPPLIER_USER_ID   => $supplierId,
                    OrderItem::PRODUCT_NAME       => $item->productVariant->{\App\Models\ProductVariant::NAME},
                    OrderItem::VARIANT_SKU        => $item->productVariant->{\App\Models\ProductVariant::SKU},
                    OrderItem::QUANTITY_DETAILS   => $item->{CartItem::QUANTITIES},
                    OrderItem::UNIT_PRICE         => $item->calculated_unit_price,
                    OrderItem::TOTAL_PRICE        => $item->calculated_line_total,
                ]);
            }

            // ج) کسر موجودی انبار (از طریق سرویس ایمن)
            $this->inventoryService->deductStockForOrder($order);

            // د) حذف سبد خرید
            $cart->items()->delete();
            $cart->delete();

            return $order;
        });

        // ۵. شلیک رویداد (برای ارسال پیامک و ...)
        OrderPlaced::dispatch($order);

        return $order;
    }

    // --- متدهای کمکی (Private Helpers) ---

    private function getCart(User $user): ?Cart
    {
        return Cart::where(Cart::USER_ID, $user->id)
            ->with([
                'items.productVariant.product.offers',
                'items.factory',
                'items.factoryLocation',
                'customerType',
                'badge',
                'priceFilterCategories'
            ])->first();
    }

    private function validateStockAvailability(Cart $cart): void
    {
        foreach ($cart->items as $item) {
            $requestedQty = array_sum($item->{CartItem::QUANTITIES} ?? []);
            if ($item->productVariant->stock_quantity < $requestedQty) {
                throw new Exception("موجودی محصول {$item->productVariant->name} کافی نیست.");
            }
        }
    }

    private function calculateOrderTotals(Cart $cart, User $user, PlaceOrderData $data): array
    {
        $subtotal = 0.0;
        $totalVat = 0.0;
        $totalItemDiscounts = 0.0;

        $activeCode = $this->roleResolver->resolve($user);
        $customerType = CustomerType::where(CustomerType::CODE, $activeCode)->first() ?: $cart->customerType;
        $orderUnitsMap = OrderUnit::all()->keyBy(OrderUnit::ID);

        // ۱. محاسبه قیمت هر آیتم
        foreach ($cart->items as $item) {
            $pricing = $this->priceService->calculatePrice(
                $item->productVariant,
                $item->factory,
                $item->factoryLocation,
                $customerType,
                $cart->badge,
                null,
                true
            );

            // محاسبه وزن کل
            $itemWeight = 0;
            foreach ($item->{CartItem::QUANTITIES} as $unitId => $qty) {
                if ($unit = $orderUnitsMap->get($unitId)) {
                    $itemWeight += (float)$qty * (float)$unit->{OrderUnit::AMOUNT};
                }
            }

            $unitPrice = (float)$pricing['final_price'];
            $lineTotal = $itemWeight * $unitPrice;

            // محاسبه مالیات خطی
            $vatRate = (float)($pricing['vat_rate'] ?? 0);
            $lineVat = $lineTotal * ($vatRate / 100);

            // ذخیره مقادیر محاسبه شده در آبجکت آیتم (برای استفاده در مرحله ثبت)
            $item->calculated_unit_price = $unitPrice;
            $item->calculated_line_total = $lineTotal;

            $subtotal += $lineTotal;
            $totalVat += $lineVat;

            // جمع تخفیف‌های واریانت (اگر در سرویس قیمت محاسبه شده باشد)
            $itemDiscountPerUnit = (float)($pricing['variant_discount_amount'] ?? 0);
            $totalItemDiscounts += $itemDiscountPerUnit * $itemWeight;
        }

        // ۲. محاسبه تخفیف‌های سطح سبد خرید (Offers)
        $offers = $this->collectOffers($cart->items);
        $cartOfferDiscount = $this->calculateCartOffers($subtotal, $offers);

        $totalDiscount = $totalItemDiscounts + $cartOfferDiscount;
        $shippingCost = 0.0;


        $finalAmount = $subtotal - $cartOfferDiscount + $totalVat + $shippingCost;

        // ۴. محاسبه کارمزدها (Fees)
        $fees = $this->calculateFees($user, $data->beneficiaryUserId, $subtotal);

        return [
            'subtotal'       => $subtotal,
            'vat_amount'     => $totalVat,
            'total_discount' => $totalDiscount,
            'shipping_cost'  => $shippingCost,
            'final_amount'   => max(0, $finalAmount),
            'fees'           => $fees,
        ];
    }

    private function collectOffers(Collection $items): Collection
    {
        return $items->flatMap(function (CartItem $item) {
            $qty = array_sum($item->{CartItem::QUANTITIES});
            return $item->productVariant->product->offers()->current()
                ->where(Offer::MINIMUM_QUANTITY, '<=', $qty)
                ->get();
        })->unique(Offer::ID);
    }

    private function calculateCartOffers(float $subtotal, Collection $offers): float
    {
        $discount = 0.0;
        foreach ($offers as $offer) {
            if ($offer->{Offer::TYPE} === 'percentage') {
                $discount += $subtotal * ($offer->{Offer::VALUE} / 100);
            } elseif ($offer->{Offer::TYPE} === 'fixed_amount') {
                $discount += (float)$offer->{Offer::VALUE};
            }
        }
        return min($discount, $subtotal);
    }

    private function calculateFees(User $placer, ?int $beneficiaryId, float $amount): array
    {
        $fees = ['engineer' => null, 'contractor' => null];

        if (!$beneficiaryId || $beneficiaryId == $placer->id) {
            return $fees;
        }

        $beneficiary = User::find($beneficiaryId);
        if (!$beneficiary) return $fees;

        $engineerTypeId = UserType::where('slug', 'engineer')->value('id');
        $contractorTypeId = UserType::where('slug', 'contractor')->value('id');

        if ($engineerTypeId && $beneficiary->{User::TYPE_ID} == $engineerTypeId) {
            $percentage = config('elitezon.consultant_fee_percentage', 2.0);
            $fees['engineer'] = ($amount * $percentage) / 100;
        } elseif ($contractorTypeId && $beneficiary->{User::TYPE_ID} == $contractorTypeId) {
            $percentage = config('elitezon.contractor_fee_percentage', 2.0);
            $fees['contractor'] = ($amount * $percentage) / 100;
        }

        return $fees;
    }
}
