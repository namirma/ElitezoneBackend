<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Offer;
use Carbon\Carbon;

class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Offer::ID               => $this->id,
            Offer::TITLE            => $this->title,
            Offer::DESCRIPTION      => $this->description,
            Offer::CODE             => $this->code,
            Offer::TYPE             => $this->type,
            Offer::VALUE            => $this->value,
            Offer::START_DATE => $this->start_date
                ? Carbon::parse($this->start_date)->format('Y-m-d H:i:s')
                : null,
            Offer::END_DATE   => $this->end_date
                ? Carbon::parse($this->end_date)->format('Y-m-d H:i:s')
                : null,
            Offer::CREATED_AT => $this->created_at
                ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s')
                : null,
            Offer::UPDATED_AT => $this->updated_at
                ? Carbon::parse($this->updated_at)->format('Y-m-d H:i:s')
                : null,
            Offer::MINIMUM_QUANTITY => $this->minimum_quantity,
            Offer::IS_ACTIVE        => (bool) $this->is_active,

            'pivot' => $this->whenPivotLoaded('product_offers', function () {
                return [
                    'product_id'    => $this->pivot->product_id,
                    'offer_id'      => $this->pivot->offer_id,
                    'associated_at' => $this->pivot->associated_at
                        ? Carbon::parse($this->pivot->associated_at)->format('Y-m-d H:i:s')
                        : null,
                ];
            }),
        ];
    }
}
