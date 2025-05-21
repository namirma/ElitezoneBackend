<?php

namespace App\Http\Resources;

use App\Models\PurchaseParameterValue;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseParameterValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            PurchaseParameterValue::ID                     => $this->id,
            PurchaseParameterValue::GROUP_ID               => $this->group_id,
            PurchaseParameterValue::NAME                   => $this->name,
            PurchaseParameterValue::SLUG                   => $this->slug,
            PurchaseParameterValue::CODE                   => $this->code,
            PurchaseParameterValue::ORDER                  => $this->order,
            PurchaseParameterValue::IS_ACTIVE              => (bool) $this->is_active,
            PurchaseParameterValue::PRICE_FILTER_CATEGORY_ID => $this->price_filter_category_id,
            PurchaseParameterValue::CREATED_AT             => $this->created_at
                ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s')
                : null,
            PurchaseParameterValue::UPDATED_AT             => $this->updated_at
                ? Carbon::parse($this->updated_at)->format('Y-m-d H:i:s')
                : null,
        ];
    }
}
