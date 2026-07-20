<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\Search
 */
class SearchResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'related_searches' => $this['related_searches'],
            'latest_searches' => $this['latest_searches'],
            'popular_searches' => $this['popular_searches'],
        ];
    }
}
