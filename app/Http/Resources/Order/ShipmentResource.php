<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\BaseJsonResource;
use App\Models\Shipment;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\Shipment
 */
class ShipmentResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Shipment::ID => $this->id,

            'origin' => [
                'factory_id' => $this->factory_id,
                'factory_location_id' => $this->factory_location_id,
                'factory_name' => $this->whenLoaded('originFactory', fn () => $this->originFactory?->name),
                'location_name' => $this->whenLoaded('originFactoryLocation', fn () => $this->originFactoryLocation?->name),
            ],

            'destination' => [
                'address_id' => $this->address_id,
                'province' => $this->whenLoaded('destinationAddress', fn () => $this->destinationAddress?->province?->name),
                'city' => $this->whenLoaded('destinationAddress', fn () => $this->destinationAddress?->city?->name),
                'full_address' => $this->whenLoaded('destinationAddress', fn () => $this->destinationAddress?->address),
                'receiver' => $this->whenLoaded('destinationAddress', fn () => $this->destinationAddress?->receiver_name),
            ],

            'weight' => (string) $this->weight,
            Shipment::SHIPPING_COST => (string) $this->shipping_cost,
            Shipment::STATUS => $this->status,
        ];
    }
}
