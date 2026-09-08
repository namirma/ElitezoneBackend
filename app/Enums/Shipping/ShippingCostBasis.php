<?php

namespace App\Enums\Shipping;

use App\Models\ShippingRate;
use App\Services\Shipping\ShippingCostCalculator;

/**
 * How a factory/supplier bills freight for a shipment leg.
 *
 * A shipment's cost is always `flat_cost + variable`, where the VARIABLE part is driven
 * by one of these bases (chosen per ShippingRate row, i.e. per origin→destination route):
 *
 *  - WEIGHT: variable = per_kg_cost  × total logistics weight (kg). Used for goods sold by
 *            mass, e.g. میلگرد (rebar) — freight scales with tonnage.
 *  - COUNT:  variable = per_count_cost × total sales count (items). Used for goods the
 *            supplier charges per piece/pack regardless of weight, e.g. آجر (brick),
 *            وال‌مش (wall-mesh).
 *
 * @see ShippingCostCalculator
 * @see ShippingRate
 */
enum ShippingCostBasis: string
{
    case WEIGHT = 'weight';

    case COUNT = 'count';

    /**
     * The per-line metric key this basis multiplies — also the key the
     * ShippingCostCalculator allocates a shipment's cost by.
     */
    public function measureKey(): string
    {
        return match ($this) {
            self::WEIGHT => 'weight',
            self::COUNT => 'count',
        };
    }

    /** Localized human label for resources/admin UI. */
    public function label(): string
    {
        return __('shipping.basis.'.$this->value);
    }

    /**
     * Resolve a (possibly null / string) stored value into a basis, defaulting to WEIGHT
     * so legacy rows and missing values behave exactly like the original weight-only model.
     */
    public static function fromValue(self|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom((string) $value) ?? self::WEIGHT;
    }
}
