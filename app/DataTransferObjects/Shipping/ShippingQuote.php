<?php

namespace App\DataTransferObjects\Shipping;

/**
 * Result of a freight calculation for a set of order/cart lines shipping to one
 * destination from one or more origin factories.
 */
class ShippingQuote
{
    public function __construct(
        /** Total freight across every factory-origin group (Rials). */
        public readonly float $total,
        /** Cost allocated to each line, keyed by the line key passed in (e.g. cart item id). */
        public readonly array $perLine,
        /** Cost grouped by origin factory_location_id. */
        public readonly array $perLocation,
    ) {}
}
