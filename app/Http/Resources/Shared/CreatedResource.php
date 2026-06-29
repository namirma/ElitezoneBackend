<?php

namespace App\Http\Resources\Shared;

use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

class CreatedResource extends BaseJsonResource
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
        ];
    }
}
