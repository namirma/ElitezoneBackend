<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\ProductVideo;
use Illuminate\Http\Request;

/**
 * @mixin ProductVideo
 */
class ProductVideoResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ProductVideo::ID => (string) $this->id,
            ProductVideo::PRODUCT_ID => (string) $this->product_id,
            ProductVideo::VIDEO_PATH => $this->video_path,
            ProductVideo::THUMBNAIL_PATH => $this->thumbnail_path,
            ProductVideo::TITLE => $this->title,
            ProductVideo::SORT_ORDER => (string) $this->sort_order,
            ProductVideo::CREATED_AT => optional($this->created_at)->format('Y-m-d H:i:s'),
            ProductVideo::UPDATED_AT => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
