<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Faq extends Model
{
    use HasFactory;
    use SoftDeletes;

    const TABLE = 'faqs';

    const ID = 'id';

    const QUESTION = 'question';

    const ANSWER = 'answer';

    const CATEGORY = 'category';

    const SORT_ORDER = 'sort_order';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /** کش لیست فعال برای نمایش عمومی (ContentController) */
    const CACHE_KEY = 'content:faqs:active';

    protected $table = self::TABLE;

    protected $fillable = [
        self::QUESTION,
        self::ANSWER,
        self::CATEGORY,
        self::SORT_ORDER,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::SORT_ORDER => 'integer',
        self::IS_ACTIVE => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
        static::restored(fn () => Cache::forget(self::CACHE_KEY));
    }
}
