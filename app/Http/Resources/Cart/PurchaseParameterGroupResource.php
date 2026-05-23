<?php

namespace App\Http\Resources\Cart;

use App\Models\PurchaseParameterGroup;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

class PurchaseParameterGroupResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            PurchaseParameterGroup::ID => $this->id,
            PurchaseParameterGroup::NAME => $this->name,
            PurchaseParameterGroup::CODE => $this->code,
            PurchaseParameterGroup::ORDER => $this->order,
            PurchaseParameterGroup::CREATED_AT => $this->created_at
                ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s')
                : null,
            PurchaseParameterGroup::UPDATED_AT => $this->updated_at
                ? Carbon::parse($this->updated_at)->format('Y-m-d H:i:s')
                : null,
            'values' => PurchaseParameterValueResource::collection(
                $this->whenLoaded('values')
            ),
        ];
    }
}
