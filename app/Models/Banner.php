<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Banner extends Model
{
    use HasFactory;
    use PersianDate;
    use SoftDeletes;

    const TABLE = 'banners';

    const ID = 'id';

    const TITLE = 'title';

    const SUBTITLE = 'subtitle';

    const IMAGE = 'image';

    const LINK = 'link';

    const POSITION = 'position';

    const CATEGORY_ID = 'category_id';

    const SORT_ORDER = 'sort_order';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /** کش لیست فعال برای نمایش عمومی (ContentController) */
    const CACHE_KEY = 'content:banners:active';

    const POSITION_CATEGORY = 'category_banner';

    /**
     * جایگاه‌های نمایش — «مقدار → برچسبِ فارسی». تنها منبعِ حقیقتِ این فهرست.
     *
     * سه مصرف‌کننده از همین آرایه می‌خوانند و هیچ‌کدام کپیِ دیگری ندارد:
     *   ۱. `Rule::in` در Store/UpdateBannerRequest (جایگاهِ ناشناخته = ۴۲۲)
     *   ۲. گزینه‌های فیلدِ `position` بلوکِ `banner_slot` در `PageBlockSchema`
     *   ۳. بک‌آفیس (`BannersPage`) نسخه‌ی دستیِ خودش را دارد، چون این فایل را
     *      نمی‌بیند — `bannerPositions.test.js` آن‌جا همین آرایه را از روی
     *      دیسک می‌خواند و برابری را پین می‌کند.
     *
     * جایی که هر جایگاه روی سایت دیده می‌شود:
     *   `home_main` / `home_banners` → هر صفحه‌ای که ادمین در صفحه‌ساز یک بلوکِ
     *     «جایگاهِ بنر» با همین جایگاه بگذارد (هیچ صفحه‌ای پیش‌فرض ندارد)
     *   `category_banner` → هیروِ صفحه‌ی دسته‌بندیِ محصول (`/rebar?category=…`)،
     *     به‌ازای `category_id`
     *   `checkout_top` → بالای صفحه‌ی تسویه (`/order`)
     *
     * @var array<string, string>
     */
    const POSITIONS = [
        'home_main' => 'اسلایدر اصلی',
        'home_banners' => 'بنرهای میانی',
        'category_banner' => 'بنر دسته‌بندی',
        'checkout_top' => 'بالای صفحه‌ی تسویه',
    ];

    protected $table = self::TABLE;

    protected $fillable = [
        self::TITLE,
        self::SUBTITLE,
        self::IMAGE,
        self::LINK,
        self::POSITION,
        self::CATEGORY_ID,
        self::SORT_ORDER,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::CATEGORY_ID => 'integer',
        self::SORT_ORDER => 'integer',
        self::IS_ACTIVE => 'boolean',
    ];

    /** @return list<array{value: string, label: string}> — شکلِ `options`ِ فیلدِ select در اسکیمای بلوک‌ها */
    public static function positionOptions(): array
    {
        $options = [];

        foreach (self::POSITIONS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, self::CATEGORY_ID);
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
        static::restored(fn () => Cache::forget(self::CACHE_KEY));
    }
}
