<?php

namespace App\Http\Resources\Shared;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

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
