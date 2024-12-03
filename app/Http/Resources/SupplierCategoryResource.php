<?php

namespace App\Http\Resources;

use App\Models\SupplierActivityCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property mixed $id
 * @property mixed $name
 * @property mixed $slug
 * @property mixed $parent_id
 * @property mixed $children
 * @method relationLoaded(string $string)
 */
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
            SupplierActivityCategory::ID => $this->id,
            SupplierActivityCategory::NAME => $this->name,
            SupplierActivityCategory::SLUG => $this->slug,
            SupplierActivityCategory::PARENT_ID => $this->parent_id,
            'children' => $this->when(
                $this->relationLoaded('children') && $this->children->isNotEmpty(),
                SupplierCategoryResource::collection($this->children)
            ),
        ];
    }
}
