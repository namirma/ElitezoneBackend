<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Page extends Model
{
    use HasFactory;
    use SoftDeletes;

    const TABLE = 'pages';

    const ID = 'id';

    const TITLE = 'title';

    const SLUG = 'slug';

    const BODY = 'body';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /** کش لیست فعال برای نمایش عمومی (ContentController) */
    const CACHE_KEY = 'content:pages:active';

    protected $table = self::TABLE;

    /** صفحه از بلوک ساخته می‌شود یا بدنه‌ی متنیِ ساده دارد (فازِ ۳). */
    const HAS_BLOCKS = 'has_blocks';

    /** اسلات‌های سطحِ صفحه — همان ویجتِ `style`، در دامنه‌ی صفحه. */
    const STYLE = 'style';

    /** مسیرِ واقعیِ سایت. ⚠️ همان اسلاگ نیست: `steel-kavir` → `/steel-kavirCo`. */
    const ROUTE = 'route';

    /** فهرستِ صفحه‌های بلوکی — جایگزینِ `PageBlock::PAGES`. */
    const BLOCK_PAGES_CACHE_KEY = 'content:pages:block_pages';

    protected $fillable = [
        self::TITLE,
        self::SLUG,
        self::BODY,
        self::IS_ACTIVE,
        self::HAS_BLOCKS,
        self::STYLE,
        self::ROUTE,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
        self::HAS_BLOCKS => 'boolean',
        self::STYLE => 'array',
    ];

    /**
     * صفحه‌هایی که از بلوک ساخته می‌شوند — `[{slug, title, route}]`.
     *
     * تنها منبعِ حقیقت برای «کدام صفحه‌ها بلوکی‌اند». پیش از فازِ ۳ این یک
     * آرایه‌ی ثابت در `PageBlock` بود با **سه کپیِ دستی** در بک‌آفیس؛ حالا هر
     * چهار مصرف‌کننده از همین‌جا می‌خوانند و `AdminPageBlockController::schema()`
     * همین را روی سیم می‌گذارد.
     *
     * @return array<string, array{id: int, slug: string, title: string, route: string, style: array<string, string>}>
     */
    public static function blockPages(): array
    {
        return Cache::remember(self::BLOCK_PAGES_CACHE_KEY, 3600, fn () => self::query()
            ->where(self::HAS_BLOCKS, true)
            ->where(self::IS_ACTIVE, true)
            ->orderBy(self::ID)
            ->get([self::ID, self::SLUG, self::TITLE, self::ROUTE, self::STYLE])
            ->keyBy(self::SLUG)
            ->map(fn (self $p) => [
                // `id` روی سیم است چون پنل برای ذخیره‌ی استایلِ صفحه به
                // `PUT /admin/pages/{id}` نیاز دارد و اسلاگ کلیدِ آن مسیر نیست.
                'id' => $p->{self::ID},
                'slug' => $p->{self::SLUG},
                'title' => $p->{self::TITLE},
                // صفحه‌ی ساخته‌شده توسط ادمین مسیرِ اختصاصی ندارد و زیرِ `/p/` می‌نشیند.
                'route' => $p->{self::ROUTE} ?: '/p/'.$p->{self::SLUG},
                // `?: []` و نه `?? []`: ستونِ JSON روی ردیفِ سیدشده `null` است،
                // ولی یک `{}` ذخیره‌شده هم باید به آرایه‌ی خالی تبدیل شود تا
                // فرانت یک شکل ببیند.
                'style' => $p->{self::STYLE} ?: [],
            ])
            ->all());
    }

    /** اسلاگ‌های بلوکی — برای `Rule::in` و گاردِ ۴۰۴. */
    public static function blockPageSlugs(): array
    {
        return array_keys(self::blockPages());
    }

    protected static function booted(): void
    {
        // هر دو کلید با هم پاک می‌شوند: یک صفحه‌ی تازه هم در فهرستِ عمومی اثر
        // دارد و هم در فهرستِ صفحه‌های بلوکی. جدا کردنشان یعنی یکی بی‌صدا کهنه بماند.
        $flush = function (): void {
            Cache::forget(self::CACHE_KEY);
            Cache::forget(self::BLOCK_PAGES_CACHE_KEY);
        };

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }
}
