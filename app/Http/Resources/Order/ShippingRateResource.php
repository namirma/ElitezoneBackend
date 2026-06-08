<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\BaseJsonResource;
use App\Models\FactoryLocation;
use App\Models\ShippingRate;

/**
 * @mixin ShippingRate
 */
class ShippingRateResource extends BaseJsonResource
{
    public function toArray($request): array
    {
        $basis = $this->{ShippingRate::COST_BASIS};

        return [
            'id'                      => $this->id,
            'factory_location_id'     => $this->{ShippingRate::FACTORY_LOCATION_ID},
            'factory_location_name'   => $this->factoryLocation?->{FactoryLocation::NAME} ?? null,
            'destination_province_id' => $this->{ShippingRate::DESTINATION_PROVINCE_ID},
            'destination_city_id'     => $this->{ShippingRate::DESTINATION_CITY_ID},
            'flat_cost'               => (float) $this->{ShippingRate::FLAT_COST},
            'per_kg_cost'             => (float) $this->{ShippingRate::PER_KG_COST},
            'cost_basis'              => $basis?->value ?? 'weight',
            'cost_basis_label'        => $basis?->label() ?? __('shipping.basis.weight'),
            'per_count_cost'          => (float) $this->{ShippingRate::PER_COUNT_COST},
            'is_active'               => (bool) $this->{ShippingRate::IS_ACTIVE},
            'created_at'              => $this->created_at?->toDateTimeString(),
        ];
    }
}
