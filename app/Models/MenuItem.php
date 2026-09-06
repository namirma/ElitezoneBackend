<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * آیتم منوی سایت (مگامنوی نوار بالا) — درختِ حداکثر سه‌سطحی via parent_id.
 *
 * `url = null` یعنی آیتم فقط یک عنوان است و لینک نیست؛ فرانت آن را غیرقابل‌کلیک
 * رندر می‌کند تا هرگز «لینکِ مرده» (وضعیت قبلی: action:'') وجود نداشته باشد.
 */
class MenuItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    const TABLE = 'menu_items';

    const ID = 'id';

    const PARENT_ID = 'parent_id';

    const LOCATION = 'location';

    const TITLE = 'title';

    const URL = 'url';

    const IS_HIGHLIGHTED = 'is_highlighted';

    const SORT_ORDER = 'sort_order';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /** حداکثر عمق مجاز درخت (اصلی → سرستون → گزینه) */
    const MAX_DEPTH = 3;

    /** جایگاه‌های مجاز منو */
    const LOCATION_HEADER = 'header';

    const LOCATION_FOOTER = 'footer';

    const LOCATIONS = [self::LOCATION_HEADER, self::LOCATION_FOOTER];

    /** کش درختِ فعالِ منو برای نمایش عمومی (به‌ازای هر جایگاه) */
    const CACHE_KEY = 'content:menu:active_tree';

    public static function cacheKeyFor(string $location): string
    {
        return self::CACHE_KEY.':'.$location;
    }

    protected $table = self::TABLE;

    protected $fillable = [
        self::PARENT_ID,
        self::LOCATION,
        self::TITLE,
        self::URL,
        self::IS_HIGHLIGHTED,
        self::SORT_ORDER,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::PARENT_ID => 'integer',
        self::IS_HIGHLIGHTED => 'boolean',
        self::SORT_ORDER => 'integer',
        self::IS_ACTIVE => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, self::PARENT_ID);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, self::PARENT_ID)
            ->orderBy(self::SORT_ORDER)
            ->orderBy(self::ID);
    }

    /**
     * عمقِ این آیتم در درخت (۱ = آیتم اصلی نوار).
     * برای اعتبارسنجیِ «بیش از سه سطح نشو» در FormRequest استفاده می‌شود.
     */
    public function depth(): int
    {
        $depth = 1;
        $parent = $this->parent;

        while ($parent !== null && $depth < 10) {
            $depth++;
            $parent = $parent->parent;
        }

        return $depth;
    }

    protected static function booted(): void
    {
        $flush = function () {
            foreach (self::LOCATIONS as $location) {
                Cache::forget(self::cacheKeyFor($location));
            }
        };

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }
}
