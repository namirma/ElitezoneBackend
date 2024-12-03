<?php

namespace App\Http\Resources;

use App\Models\ActivityCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ActivityCategory::ID => $this->id,
            ActivityCategory::NAME => $this->name,
            ActivityCategory::SLUG => $this->slug,
            ActivityCategory::PARENT_ID => $this->parent_id,
            'children' => $this->when(
                $this->relationLoaded('children') && $this->children->isNotEmpty(),
                SupplierCategoryResource::collection($this->children)
            ),
        ];
    }
}
