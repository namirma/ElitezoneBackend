<?php

namespace App\Http\Resources\Ticket;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\Department
 */
class DepartmentResource extends BaseJsonResource
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
            'description' => $this->description,
        ];
    }
}
