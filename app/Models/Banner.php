<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Banner extends Model
{
    use HasFactory;
    use PersianDate;
    use SoftDeletes;

    const TABLE = 'banners';

    const ID = 'id';

    const TITLE = 'title';

    const IMAGE = 'image';

    const LINK = 'link';

    const POSITION = 'position';

    const SORT_ORDER = 'sort_order';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /** کش لیست فعال برای نمایش عمومی (ContentController) */
    const CACHE_KEY = 'content:banners:active';

    protected $table = self::TABLE;

    protected $fillable = [
        self::TITLE,
        self::IMAGE,
        self::LINK,
        self::POSITION,
        self::SORT_ORDER,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::SORT_ORDER => 'integer',
        self::IS_ACTIVE => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => \Illuminate\Support\Facades\Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => \Illuminate\Support\Facades\Cache::forget(self::CACHE_KEY));
        static::restored(fn () => \Illuminate\Support\Facades\Cache::forget(self::CACHE_KEY));
    }
}
