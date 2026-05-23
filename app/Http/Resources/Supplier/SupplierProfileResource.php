<?php

namespace App\Http\Resources\Supplier;

use App\Models\SupplierProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property mixed $activities
 */
class SupplierProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            SupplierProfile::ACTIVITY_DESCRIPTION => $this->activity_description,
            SupplierProfile::BRANDS => $this->brands,
            SupplierProfile::BRANDS_NOT_RELATED => $this->brands_not_related,
            SupplierProfile::ACTIVITY_HISTORY => $this->activity_history,
            SupplierProfile::ACTIVITY_PROVINCES => $this->activity_provinces,
            SupplierProfile::PRODUCTION_AMOUNT => $this->production_amount,
            SupplierProfile::STANDARDS => $this->standards,
            SupplierProfile::STANDARDS_NOT_RELATED => $this->standards_not_related,
            SupplierProfile::WEBSITE => $this->website,
            SupplierProfile::WEBSITE_NOT_RELATED => $this->website_not_related,
            SupplierProfile::COMPANY => $this->company,
            SupplierProfile::CREATED_AT => $this->created_at_,
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,

            'activity_categories' => SupplierCategoryResource::collection($this->activities),
        ];
    }
}
