<?php

namespace App\Http\Resources\Supplier;

use App\Models\SupplierActivityCategory;
use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

/**
 * @property mixed $id
 * @property mixed $name
 * @property mixed $slug
 * @property mixed $parent_id
 * @property mixed $children
 *
 * @method relationLoaded(string $string)
 */
class SupplierCategoryResource extends BaseJsonResource
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
