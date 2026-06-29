<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\Shipping\ShippingCostBasis;

/**
 * One computed shipment: the items sharing a single origin (factory location) and a
 * single destination (address), with the freight for that leg. PlaceOrderAction turns
 * each of these into a Shipment row.
 */
class ShipmentGroup
{
    public function __construct(
        public readonly ?int $factoryId,
        public readonly ?int $factoryLocationId,
        public readonly ?int $addressId,
        public readonly ?int $destinationProvinceId,
        public readonly ?int $destinationCityId,
        /** Total logistics weight (kg) carried by this leg. */
        public readonly float $weight,
        /** Total sales count (items) carried by this leg — the count-basis driver. */
        public readonly float $count,
        /** Which basis priced this leg (weight vs count). */
        public readonly ShippingCostBasis $costBasis,
        public readonly float $cost,
        /** The line keys (e.g. cart item ids) that belong to this shipment. */
        public readonly array $lineKeys,
    ) {}
}
