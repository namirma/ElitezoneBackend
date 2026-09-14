<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * یک بلوک روی یکی از سه صفحه‌ی محتواییِ سایت.
 *
 * `data` عمداً JSON است: پانزده نوعِ بلوک فیلدهای کاملاً متفاوتی دارند و ستونی
 * کردنشان یا پانزده جدول می‌خواهد یا یک جدولِ پر از NULL. بی‌قاعده هم نیست —
 * `PageBlockSchema` هم فرمِ پنل را می‌سازد هم قواعدِ اعتبارسنجی را، از یک جا.
 */
class PageBlock extends Model
{
    use SoftDeletes;

    const TABLE = 'page_blocks';

    const ID = 'id';

    const PAGE = 'page';

    const TYPE = 'type';

    const SORT_ORDER = 'sort_order';

    const IS_ACTIVE = 'is_active';

    const DATA = 'data';

    /** کلیدِ صفحه یک شناسه‌ی پایدار است، نه مسیرِ سایت. */
    const PAGE_HOME = 'home';

    const PAGE_PODIUM = 'podium';

    const PAGE_STEEL_KAVIR = 'steel-kavir';

    /**
     * ⚠️ **دیگر فهرستِ صفحه‌های بلوکی نیست** — فازِ ۳ (۲۰۲۶-۰۹-۱۲).
     *
     * صفحه حالا یک **رکورد** در `pages` است (`has_blocks = true`) تا ادمین
     * بتواند صفحه‌ی تازه بسازد. تنها منبعِ حقیقت: `Page::blockPages()`.
     *
     * این ثابت فقط سه صفحه‌ی *اولیه* را نگه می‌دارد و صرفاً در
     * `cachedPageKeys()` به‌عنوان کفِ فلاشِ کش استفاده می‌شود — تا اگر ردیفِ
     * `pages` هنوز سید نشده باشد، کشِ همان سه صفحه‌ی زنده باز هم پاک شود.
     * برای اعتبارسنجی یا گاردِ ۴۰۴ **از آن استفاده نکنید**؛ صفحه‌ی ساخته‌شده
     * توسط ادمین در آن نیست و بی‌صدا ۴۲۲/۴۰۴ می‌گیرد.
     */
    const PAGES = [self::PAGE_HOME, self::PAGE_PODIUM, self::PAGE_STEEL_KAVIR];

    const CACHE_KEY = 'content:page_blocks';

    protected $table = self::TABLE;

    protected $fillable = [
        self::PAGE,
        self::TYPE,
        self::SORT_ORDER,
        self::IS_ACTIVE,
        self::DATA,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
        self::DATA => 'array',
    ];

    public static function cacheKeyFor(string $page): string
    {
        return self::CACHE_KEY.':'.$page;
    }

    /**
     * کلیدهایی که هنگام فلاش باید پاک شوند = صفحه‌های بلوکیِ رکوردشده
     * **به‌علاوه‌ی** سه صفحه‌ی اولیه.
     *
     * اجتماع عمدی است: `Page::blockPages()` فقط صفحه‌های `is_active` را
     * برمی‌گرداند، پس اگر ادمین صفحه‌ای را غیرفعال کند کشِ قبلی‌اش بدونِ این
     * کف تا انقضای TTL روی سایت می‌ماند.
     *
     * @return list<string>
     */
    public static function cachedPageKeys(): array
    {
        return array_values(array_unique([...self::PAGES, ...Page::blockPageSlugs()]));
    }

    /**
     * کشِ **همه‌ی** صفحه‌ها فلاش می‌شود، نه فقط صفحه‌ی همین ردیف.
     *
     * دلیل: `$model->page` بعد از `update(['page' => ...])` مقدارِ **جدید** است،
     * پس کشِ صفحه‌ی قبلی دست‌نخورده می‌ماند و بلوک تا انقضای TTL روی صفحه‌ی
     * قدیمی باقی می‌ماند. سه کلید است؛ فلاشِ هر سه ارزان‌تر از این باگ است.
     * همان الگوی `MenuItem::booted()`.
     */
    protected static function booted(): void
    {
        $flush = function (): void {
            foreach (self::cachedPageKeys() as $page) {
                Cache::forget(self::cacheKeyFor($page));
            }
        };

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }
}
