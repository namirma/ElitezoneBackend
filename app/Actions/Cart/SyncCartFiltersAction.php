<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Services\PriceCalculationService;
use App\Services\PricingRoleResolver;
use Illuminate\Support\Facades\DB;

class SyncCartFiltersAction
{
    public function __construct(
        protected PriceCalculationService $priceService,
        protected PricingRoleResolver $roleResolver
    ) {}

    public function execute(Cart $cart, array $filterCategoryIds): Cart
    {
        return DB::transaction(function () use ($cart, $filterCategoryIds) {

            $cart->priceFilterCategories()->sync($filterCategoryIds);

            $cart->loadMissing(['items.productVariant', 'user', 'customerType', 'badge', 'priceFilterCategories']);

            $user = $cart->user;
            $roleCode = $user ? $this->roleResolver->resolve($user) : 'employer_without_consultant';
            $customerType = $cart->customerType ?? CustomerType::where('code', $roleCode)->first();
            $filterIds = $cart->priceFilterCategories->pluck('id')->toArray();

            foreach ($cart->items as $cartItem) {
                $factory = Factory::find($cartItem->factory_id);
                $location = FactoryLocation::find($cartItem->factory_location_id);

                if (!$factory || !$location) continue;

                $pricing = $this->priceService->calculatePrice(
                    variant: $cartItem->productVariant,
                    factory: $factory,
                    location: $location,
                    customerType: $customerType,
                    badge: $cart->badge,
                    filterCategoryIds: $filterIds,
                    withVAT: true,
                    filterCategoryCode: $roleCode
                );

                $weight = (float) $cartItem->weight;
                $lineTotal = $weight * $pricing->finalPrice;
                $lineVat   = $weight * $pricing->vatAmount;

                $cartItem->update([
                    'price'          => $pricing->finalPrice,
                    'final_price'    => $pricing->finalPrice,
                    'vat_amount'     => $lineVat,
                    'total_with_vat' => $lineTotal + $lineVat,
                ]);
            }

            return $cart->fresh(['items.productVariant']);
        });
    }
}
