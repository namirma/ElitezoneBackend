<?php

namespace App\DataTransferObjects\Shipping;

/**
 * Result of a freight calculation for a set of order/cart lines. Supports multiple
 * origins AND multiple destinations: lines are grouped into shipments by
 * (origin factory location → destination address), each shipment priced on its own route.
 */
class ShippingQuote
{
    public function __construct(
        /** Total freight across every shipment (Rials). */
        public readonly float $total,
        /** Cost allocated to each line, keyed by the line key passed in (e.g. cart item id). */
        public readonly array $perLine,
        /** @var ShipmentGroup[] one entry per (origin → destination) leg */
        public readonly array $shipments,
    ) {}
}
