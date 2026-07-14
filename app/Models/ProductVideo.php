<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $video_path
 * @property string|null $thumbnail_path
 * @property string|null $title
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereThumbnailPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereVideoPath($value)
 * @mixin \Eloquent
 */
class ProductVideo extends Model
{
    use HasFactory;

    const ID = 'id';

    const PRODUCT_ID = 'product_id';

    const VIDEO_PATH = 'video_path';

    const THUMBNAIL_PATH = 'thumbnail_path';

    const TITLE = 'title';

    const SORT_ORDER = 'sort_order';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_ID,
        self::VIDEO_PATH,
        self::THUMBNAIL_PATH,
        self::TITLE,
        self::SORT_ORDER,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
