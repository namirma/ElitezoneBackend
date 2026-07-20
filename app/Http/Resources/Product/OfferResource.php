<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\Offer;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\Offer
 */
class OfferResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Offer::ID => $this->id,
            Offer::TITLE => $this->title,
            Offer::DESCRIPTION => $this->description,
            Offer::CODE => $this->code,
            Offer::TYPE => $this->type,
            Offer::VALUE => $this->value,
            Offer::START_DATE => $this->start_date
                ? Carbon::parse($this->start_date)->format('Y-m-d H:i:s')
                : null,
            Offer::END_DATE => $this->end_date
                ? Carbon::parse($this->end_date)->format('Y-m-d H:i:s')
                : null,
            Offer::MINIMUM_QUANTITY => $this->minimum_quantity,
            Offer::IS_ACTIVE => (bool) $this->is_active,

        ];
    }
}
