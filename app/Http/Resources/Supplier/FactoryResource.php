<?php

namespace App\Http\Resources\Supplier;

use App\Http\Resources\BaseJsonResource;
use App\Models\Factory;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * @mixin Factory
 */
class FactoryResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Factory::ID => $this->id,
            Factory::NAME => $this->name,
            Factory::SLUG => $this->slug,
            Factory::DESCRIPTION => $this->description,
            Factory::LOGO => $this->logo,
            Factory::API_KEY => $this->api_key,
            Factory::API_URL => $this->api_url,
            Factory::IS_ACTIVE => (bool) $this->is_active,
            Factory::CREATED_AT => $this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s') : null,
            Factory::UPDATED_AT => $this->updated_at ? Carbon::parse($this->updated_at)->format('Y-m-d H:i:s') : null,

            'locations' => FactoryLocationResource::collection($this->whenLoaded('locations')),
        ];
    }
}
