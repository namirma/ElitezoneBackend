<?php

namespace App\Services\Shipping;

use App\DataTransferObjects\Shipping\ShipmentGroup;
use App\DataTransferObjects\Shipping\ShippingQuote;
use App\Models\ShippingRate;

/**
 * Computes freight for an order whose lines may ship from several origins to several
 * destinations.
 *
 * Lines are grouped into SHIPMENTS by (origin factory location → destination address);
 * each shipment is priced with the matching ShippingRate (flat + per-kg) for the
 * destination province/city, then its cost is allocated back onto its lines proportional
 * to weight. When no rate matches a route the shipment costs 0 — so the platform degrades
 * gracefully until an admin enters rates, instead of inventing a number.
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
     *     weight?: float
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

            $cost = $rate
                ? round((float) $rate->flat_cost + ((float) $rate->per_kg_cost * $group['weight']), 2)
                : 0.0;

            $total += $cost;

            $this->allocate($cost, $group['line_keys'], $lines, $group['weight'], $perLine);

            $shipments[] = new ShipmentGroup(
                factoryId: $group['factory_id'],
                factoryLocationId: $group['factory_location_id'],
                addressId: $group['address_id'],
                destinationProvinceId: $group['destination_province_id'],
                destinationCityId: $group['destination_city_id'],
                weight: round($group['weight'], 3),
                cost: $cost,
                lineKeys: $group['line_keys'],
            );
        }

        return new ShippingQuote(round($total, 2), $perLine, $shipments);
    }

    /**
     * Group lines by (origin factory location, destination address). Each group is one
     * shipment leg. The destination province/city are carried from the lines so the rate
     * lookup has them.
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
                ];
            }

            $groups[$bucket]['line_keys'][] = $key;
            $groups[$bucket]['weight'] += max(0.0, (float) ($line['weight'] ?? 0));
        }

        return $groups;
    }

    /**
     * Split a shipment's cost across its lines, proportional to weight; the last line
     * absorbs the rounding remainder so the parts sum exactly to the shipment cost.
     */
    private function allocate(float $groupCost, array $keys, array $lines, float $groupWeight, array &$perLine): void
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

            $share = $groupWeight > 0.0
                ? round($groupCost * (max(0.0, (float) ($lines[$k]['weight'] ?? 0)) / $groupWeight), 2)
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
