<?php

namespace App\Services\Shipping;

use App\DataTransferObjects\Shipping\ShipmentGroup;
use App\DataTransferObjects\Shipping\ShippingQuote;
use App\Enums\Shipping\ShippingCostBasis;
use App\Models\ShippingRate;

/**
 * Computes freight for an order whose lines may ship from several origins to several
 * destinations.
 *
 * Lines are grouped into SHIPMENTS by (origin factory location → destination address);
 * each shipment is priced with the matching ShippingRate for the destination
 * province/city, then its cost is allocated back onto its lines.
 *
 * Cost is UNIT-AWARE — `flat_cost + variable`, where the variable component follows the
 * rate's {@see ShippingCostBasis}:
 *   - WEIGHT: per_kg_cost   × total logistics weight (kg)  — e.g. میلگرد (rebar)
 *   - COUNT:  per_count_cost × total sales count (items)   — e.g. آجر / وال‌مش (per piece)
 *
 * The cost is then split across the shipment's lines proportional to that same basis
 * metric (weight for WEIGHT, count for COUNT), so each line carries its fair share.
 *
 * When no rate matches a route the shipment costs 0 — so the platform degrades gracefully
 * until an admin enters rates, instead of inventing a number.
 *
 * Rate selection per route: a city-specific row wins; otherwise the province-level row
 * (destination_city_id = null) is the fallback.
 *
 * Single-destination orders are just the case where every line shares one address — the
 * same code path produces one shipment per origin.
 */
class ShippingCostCalculator
{
    /** @var array<string, ShippingRate|null> in-request memo of rate lookups */
    private array $rateCache = [];

    /**
     * @param  array<int|string, array{
     *     factory_id?: int|null,
     *     factory_location_id?: int|null,
     *     address_id?: int|null,
     *     destination_province_id?: int|null,
     *     destination_city_id?: int|null,
     *     weight?: float,
     *     count?: float
     * }>  $lines  keyed by an arbitrary line key (e.g. cart item id)
     */
    public function quote(array $lines): ShippingQuote
    {
        $groups = $this->groupIntoShipments($lines);

        $perLine = [];
        $shipments = [];
        $total = 0.0;

        foreach ($groups as $group) {
            $rate = $group['factory_location_id'] !== null
                ? $this->resolveRate(
                    $group['factory_location_id'],
                    $group['destination_province_id'],
                    $group['destination_city_id'],
                )
                : null;

            $basis = $rate
                ? ShippingCostBasis::fromValue($rate->{ShippingRate::COST_BASIS})
                : ShippingCostBasis::WEIGHT;

            $cost = $this->priceShipment($rate, $basis, $group);
            $total += $cost;

            // Allocate by the same metric that priced the leg, so the split is consistent
            // with the charge (weight-billed → split by weight; count-billed → by count).
            $measureKey = $basis->measureKey();
            $this->allocate($cost, $group['line_keys'], $lines, $group[$measureKey], $measureKey, $perLine);

            $shipments[] = new ShipmentGroup(
                factoryId: $group['factory_id'],
                factoryLocationId: $group['factory_location_id'],
                addressId: $group['address_id'],
                destinationProvinceId: $group['destination_province_id'],
                destinationCityId: $group['destination_city_id'],
                weight: round($group['weight'], 3),
                count: round($group['count'], 3),
                costBasis: $basis,
                ratePriced: $rate !== null,
                cost: $cost,
                lineKeys: $group['line_keys'],
            );
        }

        return new ShippingQuote(round($total, 2), $perLine, $shipments);
    }

    /**
     * Price a single shipment leg: flat_cost + (basis-driven variable component).
     */
    private function priceShipment(?ShippingRate $rate, ShippingCostBasis $basis, array $group): float
    {
        if (! $rate) {
            return 0.0;
        }

        $variable = match ($basis) {
            ShippingCostBasis::COUNT => (float) $rate->{ShippingRate::PER_COUNT_COST} * $group['count'],
            ShippingCostBasis::WEIGHT => (float) $rate->{ShippingRate::PER_KG_COST} * $group['weight'],
        };

        return round((float) $rate->{ShippingRate::FLAT_COST} + $variable, 2);
    }

    /**
     * Group lines by (origin factory location, destination address). Each group is one
     * shipment leg. The destination province/city are carried from the lines so the rate
     * lookup has them. Both weight and count are accumulated so either basis can price it.
     */
    private function groupIntoShipments(array $lines): array
    {
        $groups = [];

        foreach ($lines as $key => $line) {
            $locId = isset($line['factory_location_id']) ? (int) $line['factory_location_id'] : null;
            $addrId = isset($line['address_id']) ? (int) $line['address_id'] : null;

            $bucket = ($locId ?? '_').'|'.($addrId ?? '_');

            if (! isset($groups[$bucket])) {
                $groups[$bucket] = [
                    'factory_id' => isset($line['factory_id']) ? (int) $line['factory_id'] : null,
                    'factory_location_id' => $locId,
                    'address_id' => $addrId,
                    'destination_province_id' => isset($line['destination_province_id']) ? (int) $line['destination_province_id'] : null,
                    'destination_city_id' => isset($line['destination_city_id']) ? (int) $line['destination_city_id'] : null,
                    'line_keys' => [],
                    'weight' => 0.0,
                    'count' => 0.0,
                ];
            }

            $groups[$bucket]['line_keys'][] = $key;
            $groups[$bucket]['weight'] += max(0.0, (float) ($line['weight'] ?? 0));
            $groups[$bucket]['count'] += max(0.0, (float) ($line['count'] ?? 0));
        }

        return $groups;
    }

    /**
     * Split a shipment's cost across its lines, proportional to the basis metric
     * ($measureKey = 'weight' or 'count'); the last line absorbs the rounding remainder
     * so the parts sum exactly to the shipment cost. When the group metric is 0 the cost
     * is split evenly.
     */
    private function allocate(float $groupCost, array $keys, array $lines, float $groupMeasure, string $measureKey, array &$perLine): void
    {
        $count = count($keys);

        if ($groupCost <= 0.0) {
            foreach ($keys as $k) {
                $perLine[$k] = 0.0;
            }

            return;
        }

        $assigned = 0.0;
        foreach (array_values($keys) as $idx => $k) {
            if ($idx === $count - 1) {
                $perLine[$k] = round($groupCost - $assigned, 2);

                continue;
            }

            $lineMeasure = max(0.0, (float) ($lines[$k][$measureKey] ?? 0));
            $share = $groupMeasure > 0.0
                ? round($groupCost * ($lineMeasure / $groupMeasure), 2)
                : round($groupCost / $count, 2);

            $perLine[$k] = $share;
            $assigned += $share;
        }
    }

    private function resolveRate(int $locationId, ?int $provinceId, ?int $cityId): ?ShippingRate
    {
        if ($provinceId === null) {
            return null;
        }

        $memo = "{$locationId}:{$provinceId}:".($cityId ?? 'null');
        if (array_key_exists($memo, $this->rateCache)) {
            return $this->rateCache[$memo];
        }

        $rate = null;

        if ($cityId !== null) {
            $rate = ShippingRate::active()
                ->where(ShippingRate::FACTORY_LOCATION_ID, $locationId)
                ->where(ShippingRate::DESTINATION_PROVINCE_ID, $provinceId)
                ->where(ShippingRate::DESTINATION_CITY_ID, $cityId)
                ->first();
        }

        if (! $rate) {
            $rate = ShippingRate::active()
                ->where(ShippingRate::FACTORY_LOCATION_ID, $locationId)
                ->where(ShippingRate::DESTINATION_PROVINCE_ID, $provinceId)
                ->whereNull(ShippingRate::DESTINATION_CITY_ID)
                ->first();
        }

        return $this->rateCache[$memo] = $rate;
    }
}
