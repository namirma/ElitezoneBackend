<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property mixed $id
 * @property mixed $name
 * @property mixed $slug
 * @property mixed $parent_id
 * @property mixed $created_at
 * @property mixed $updated_at
 */
class ActivityCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'parent_id' => $this->parent_id,
            'children' => $this->whenLoaded('children'),
//            'subcategories' => $this->when($this->subcategories->isNotEmpty(), ActivityCategoryResource::collection($this->subcategories)),
        ];
    }
}
