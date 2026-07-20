<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\BaseJsonResource;
use App\Models\Address;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\Address
 */
class AddressResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Address::ID => $this->id,
            Address::USER_ID => $this->user_id,
            Address::TITLE => $this->title,
            Address::PROVINCE_ID => $this->province_id,
            Address::CITY_ID => $this->city_id,
            Address::POSTAL_ADDRESS => $this->postal_address,
            Address::BUILDING_NUMBER => $this->building_number,
            Address::UNIT => $this->unit,
            Address::POSTAL_CODE => $this->postal_code,
            Address::LATITUDE => $this->latitude,
            Address::LONGITUDE => $this->longitude,
            Address::RECEIVER_NAME => $this->receiver_name,
            Address::RECEIVER_MOBILE => $this->receiver_mobile,
            Address::IS_DEFAULT => $this->is_default,
            Address::CREATED_AT => $this->created_at?->format('Y-m-d H:i:s'),
            Address::UPDATED_AT => $this->updated_at?->format('Y-m-d H:i:s'),
            // 'province' => new ProvinceResource($this->whenLoaded('province')), // Assumes ProvinceResource exists
            // 'city' => new CityResource($this->whenLoaded('city')), // Assumes CityResource exists
        ];
    }
}
