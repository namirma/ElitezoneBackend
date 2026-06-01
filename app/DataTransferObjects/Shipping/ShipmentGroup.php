<?php

namespace App\DataTransferObjects\Shipping;

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
        public readonly float $weight,
        public readonly float $cost,
        /** The line keys (e.g. cart item ids) that belong to this shipment. */
        public readonly array $lineKeys,
    ) {}
}
