<?php

namespace App\Http\Resources\Supplier;

use App\Models\FactoryLocation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FactoryLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            FactoryLocation::ID => $this->id,
            FactoryLocation::FACTORY_ID => $this->factory_id,
            FactoryLocation::NAME => $this->name,
            FactoryLocation::TYPE => $this->type,
            FactoryLocation::CITY => $this->city,
            FactoryLocation::ADDRESS => $this->address,
            FactoryLocation::IS_ACTIVE => (bool) $this->is_active,
            FactoryLocation::CREATED_AT => $this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s') : null,
            FactoryLocation::UPDATED_AT => $this->updated_at ? Carbon::parse($this->updated_at)->format('Y-m-d H:i:s') : null,
        ];
    }
}
