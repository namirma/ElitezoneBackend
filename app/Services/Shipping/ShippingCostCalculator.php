<?php

namespace App\Services\Shipping;

use App\DataTransferObjects\Shipping\ShippingQuote;
use App\Models\ShippingRate;

/**
 * Computes freight for a multi-factory order shipping to a single destination.
 *
 * Lines are grouped by their origin factory location; each group is priced with the
 * matching ShippingRate (flat + per-kg) for the destination province/city, then the
 * group cost is allocated back onto its lines proportional to weight. When no rate
 * matches a route the group costs 0 — so the platform degrades gracefully until an
 * admin enters rates, instead of inventing a number.
 *
 * Rate selection per origin: a city-specific row wins; otherwise the province-level
 * row (destination_city_id = null) is the fallback.
 */
class ShippingCostCalculator
{
    /** @var array<string, ShippingRate|null> in-request memo of rate lookups */
    private array $rateCache = [];

    /**
     * @param  array<int|string, array{factory_location_id: int|null, weight: float}>  $lines
     *                                                                                         keyed by an arbitrary line key (e.g. cart item id)
     */
    public function quote(array $lines, ?int $destinationProvinceId, ?int $destinationCityId): ShippingQuote
    {
        // Group line keys by origin factory location.
        $groups = [];
        foreach ($lines as $key => $line) {
            $locId = $line['factory_location_id'] ?? null;
            $bucket = $locId === null ? '_null' : (string) $locId;
            $groups[$bucket][] = $key;
        }

        $perLine = [];
        $perLocation = [];
        $total = 0.0;

        foreach ($groups as $bucket => $keys) {
            $locId = $bucket === '_null' ? null : (int) $bucket;

            $groupWeight = 0.0;
            foreach ($keys as $k) {
                $groupWeight += max(0.0, (float) ($lines[$k]['weight'] ?? 0));
            }

            $rate = $locId !== null
                ? $this->resolveRate($locId, $destinationProvinceId, $destinationCityId)
                : null;

            $groupCost = $rate
                ? round((float) $rate->flat_cost + ((float) $rate->per_kg_cost * $groupWeight), 2)
                : 0.0;

            if ($locId !== null) {
                $perLocation[$locId] = ($perLocation[$locId] ?? 0.0) + $groupCost;
            }
            $total += $groupCost;

            $this->allocate($groupCost, $keys, $lines, $groupWeight, $perLine);
        }

        return new ShippingQuote(round($total, 2), $perLine, $perLocation);
    }

    /**
     * Split a group's cost across its lines, proportional to weight; the last line
     * absorbs the rounding remainder so the parts sum exactly to the group cost.
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
                ? round($groupCost * (max(0.0, (float) $lines[$k]['weight']) / $groupWeight), 2)
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
