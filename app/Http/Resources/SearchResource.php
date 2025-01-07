<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SearchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // 'results' => $this['results'],
            'related_searches' => $this['related_searches'],
            'latest_searches' => $this['latest_searches'],
            'popular_searches' => $this['popular_searches'],
        ];
    }
}
