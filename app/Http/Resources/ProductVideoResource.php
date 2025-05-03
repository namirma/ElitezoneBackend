<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\ProductVideo;

class ProductVideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductVideo::ID => $this->id,
            ProductVideo::PRODUCT_ID => $this->product_id,
            ProductVideo::VIDEO_PATH => $this->video_path,
            ProductVideo::THUMBNAIL_PATH => $this->thumbnail_path,
            ProductVideo::TITLE => $this->title,
            ProductVideo::SORT_ORDER => $this->sort_order,
            ProductVideo::CREATED_AT => $this->created_at,
            ProductVideo::UPDATED_AT => $this->updated_at,
        ];
    }
}
