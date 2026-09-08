<?php

namespace App\Http\Resources\Supplier;

use App\Http\Resources\BaseJsonResource;
use App\Models\FactoryFeature;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * @mixin FactoryFeature
 */
class FactoryFeatureResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            FactoryFeature::ID => $this->id,
            FactoryFeature::FACTORY_ID => $this->factory_id,
            FactoryFeature::CATEGORY => $this->category,
            FactoryFeature::ICON_KEY => $this->icon_key,
            FactoryFeature::LABEL => $this->label,
            FactoryFeature::ORDER => $this->order,
            FactoryFeature::IS_ACTIVE => (bool) $this->is_active,
            FactoryFeature::CREATED_AT => $this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s') : null,
            FactoryFeature::UPDATED_AT => $this->updated_at ? Carbon::parse($this->updated_at)->format('Y-m-d H:i:s') : null,
        ];
    }
}
