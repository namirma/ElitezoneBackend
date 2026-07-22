<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property string $video_path
 * @property string|null $thumbnail_path
 * @property string|null $title
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereThumbnailPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereVideoPath($value)
 *
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
