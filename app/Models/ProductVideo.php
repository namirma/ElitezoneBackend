<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVideo extends Model
{
    use HasFactory;

    const
        ID = 'id',
        PRODUCT_ID = 'product_id',
        VIDEO_PATH = 'video_path',
        THUMBNAIL_PATH = 'thumbnail_path',
        TITLE = 'title',
        SORT_ORDER = 'sort_order',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

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
