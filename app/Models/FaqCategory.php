<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * دسته‌بندی سوالات متداول — منوی کشوی پشتیبانیِ سایت اصلی از روی همین ساخته می‌شود.
 */
class FaqCategory extends Model
{
    use HasFactory;
    use SoftDeletes;

    const TABLE = 'faq_categories';

    const ID = 'id';

    const TITLE = 'title';

    const SLUG = 'slug';

    const SUBTITLE = 'subtitle';

    const SORT_ORDER = 'sort_order';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /** کش درختِ دسته‌ها + سوالاتشان برای نمایش عمومی (ContentController) */
    const CACHE_KEY = 'content:faq_categories:active';

    protected $table = self::TABLE;

    protected $fillable = [
        self::TITLE,
        self::SLUG,
        self::SUBTITLE,
        self::SORT_ORDER,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::SORT_ORDER => 'integer',
        self::IS_ACTIVE => 'boolean',
    ];

    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class, 'faq_category_id');
    }

    protected static function booted(): void
    {
        // تغییر دسته، هم کش خودش و هم کش لیست تخت سوالات را باطل می‌کند
        $flush = function () {
            Cache::forget(self::CACHE_KEY);
            Cache::forget(Faq::CACHE_KEY);
        };

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }
}
