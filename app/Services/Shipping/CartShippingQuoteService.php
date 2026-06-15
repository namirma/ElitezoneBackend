<?php

namespace App\Services\Shipping;

use App\DataTransferObjects\Shipping\ShippingQuote;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderUnit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Computes a freight quote for a cart BEFORE checkout, so the frontend can show the
 * shipping cost up-front instead of only after the order is placed.
 *
 * The per-line logistics computation (lineLogistics) is the single source of truth
 * shared with PlaceOrderAction — the pre-checkout quote therefore equals the freight
 * actually charged at placement (both feed the same ShippingCostCalculator with the
 * same weight/count metrics).
 */
class CartShippingQuoteService
{
    public function __construct(
        private readonly ShippingCostCalculator $calculator,
    ) {}

    /**
     * Per-line logistics: total logistics weight (kg, for weight-billed routes) and
     * sales count (items, for count-billed routes). A line with an unknown unit falls
     * back to the variant weight; a non-positive result is floored to 0.001 kg so a
     * line is never free purely due to missing unit data.
     *
     * @return array{weight: float, count: float, unit_name: ?string}
     */
    public function lineLogistics(CartItem $item, Collection $orderUnits): array
    {
        $weight = 0.0;
        $count = 0.0;
        $unitName = null;

        $quantities = is_string($item->quantities) ? json_decode($item->quantities, true) : $item->quantities;
        if (! is_array($quantities)) {
            $quantities = [];
        }

        if (! empty($quantities)) {
            foreach ($quantities as $unitId => $qty) {
                $qty = (float) $qty;
                $count += $qty;

                $unitModel = $orderUnits->where('id', $unitId)->first();
                if (! $unitName && $unitModel) {
                    $unitName = $unitModel->name;
                }

                if ($unitModel) {
                    $weight += $qty * (float) $unitModel->amount;
                } else {
                    $variantWeight = (float) ($item->productVariant->weight ?? 1.0);
                    if ($variantWeight <= 0) {
                        $variantWeight = 1.0;
                    }
                    $weight += $qty * $variantWeight;
                }
            }
        } else {
            $simpleQty = (float) ($item->quantity ?? 1);
            $count = $simpleQty;
            $unitName = 'عدد';
            $variantWeight = (float) ($item->productVariant->weight ?? 1.0);
            if ($variantWeight <= 0) {
                $variantWeight = 1.0;
            }
            $weight = $simpleQty * $variantWeight;
        }

        if ($weight <= 0) {
            $weight = 0.001;
        }

        return ['weight' => $weight, 'count' => $count, 'unit_name' => $unitName];
    }

    /**
     * Quote freight for the cart's current items delivered to $primaryAddressId.
     * A line may override the destination via its own address_id (multi-destination cart);
     * otherwise it ships to the primary address.
     */
    public function quoteForCart(Cart $cart, int $primaryAddressId): ShippingQuote
    {
        $items = $cart->items()
            ->with(['productVariant', 'factory', 'factoryLocation'])
            ->get();

        if ($items->isEmpty()) {
            return new ShippingQuote(total: 0.0, perLine: [], shipments: []);
        }

        $orderUnits = Cache::remember('order_units_all', 3600, fn () => OrderUnit::all());

        // Resolve every destination address referenced by the lines (per-line or primary).
        $neededAddressIds = [];
        foreach ($items as $item) {
            $addrId = $item->address_id ?: $primaryAddressId;
            if ($addrId) {
                $neededAddressIds[(int) $addrId] = true;
            }
        }
        $addresses = Address::whereIn(Address::ID, array_keys($neededAddressIds))->get()->keyBy(Address::ID);

        $lines = [];
        foreach ($items as $item) {
            $addrId = $item->address_id ?: $primaryAddressId;
            $address = $addrId ? $addresses->get((int) $addrId) : null;
            $logistics = $this->lineLogistics($item, $orderUnits);

            $lines[$item->id] = [
                'factory_id' => $item->factory_id ? (int) $item->factory_id : null,
                'factory_location_id' => $item->factory_location_id ? (int) $item->factory_location_id : null,
                'address_id' => $addrId ? (int) $addrId : null,
                'destination_province_id' => $address?->{Address::PROVINCE_ID} ? (int) $address->{Address::PROVINCE_ID} : null,
                'destination_city_id' => $address?->{Address::CITY_ID} ? (int) $address->{Address::CITY_ID} : null,
                'weight' => $logistics['weight'],
                'count' => $logistics['count'],
            ];
        }

        return $this->calculator->quote($lines);
    }
}
