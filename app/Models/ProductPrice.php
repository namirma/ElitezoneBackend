<?php

namespace App\Models;

use App\Services\Pricing\Pipes\SetBasePrice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * @property int $id
 * @property int $product_variant_id
 * @property int $factory_id
 * @property int $factory_location_id
 * @property numeric $general_price
 * @property numeric $special_price
 * @property numeric $golden_figure
 * @property Carbon $last_updated_at
 *
 * ستون نیستند — `VariantPriceHydrator` این سه را در زمان اجرا روی مدل می‌نشاند و
 * ‏`ProductVariantResource` می‌خواندشان؛ بدونشان باکس خرید مجبور بود نرخ مالیات را
 * ‏`× ۱٫۱۰` هاردکد کند.
 * @property float|null $vat_rate
 * @property numeric-string|float|null $vat_amount
 * @property numeric-string|float|null $price_with_vat
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Factory $factory
 * @property-read FactoryLocation $factoryLocation
 * @property-read ProductVariant $productVariant
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereGeneralPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereGoldenFigure($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereLastUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereSpecialPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ProductPrice extends Model
{
    use HasFactory;

    const ID = 'id';

    const PRODUCT_VARIANT_ID = 'product_variant_id';

    const FACTORY_ID = 'factory_id';

    const FACTORY_LOCATION_ID = 'factory_location_id';

    const GENERAL_PRICE = 'general_price';

    const SPECIAL_PRICE = 'special_price';

    const GOLDEN_FIGURE = 'golden_figure';

    const LAST_UPDATED_AT = 'last_updated_at';

    /**
     * مبنای آستانه‌ی تأیید — «آخرین قیمتِ تأییدشده»، نه قیمتِ فعلی.
     *
     * ⚠️ عمداً در `$fillable` **نیست**. این ستون‌ها فقط با تأییدِ ادمین جابه‌جا
     * می‌شوند (و یک بار موقعِ ساختِ ردیف). اگر fillable بودند، هر `update()` عادی
     * از پنلِ تأمین‌کننده مبنا را هم با خودش می‌برد و آستانه بی‌اثر می‌شد —
     * تأمین‌کننده با ۱۴٪ + ۱۴٪ + ۱۴٪ قیمت را ۴۸٪ جابه‌جا می‌کرد بی‌آنکه یک بار
     * به صف بخورد. با `forceFill()` نوشته می‌شوند، مثل ستون‌های اسنپ‌شاتِ Order.
     */
    const APPROVED_GENERAL_PRICE = 'approved_general_price';

    const APPROVED_SPECIAL_PRICE = 'approved_special_price';

    const APPROVED_GOLDEN_FIGURE = 'approved_golden_figure';

    const APPROVED_PRICE_AT = 'approved_price_at';

    /** ستونِ مبنا به‌ازای هر ستونِ قیمتِ مشمولِ آستانه. */
    const APPROVED_BASELINE_OF = [
        self::GENERAL_PRICE => self::APPROVED_GENERAL_PRICE,
        self::SPECIAL_PRICE => self::APPROVED_SPECIAL_PRICE,
        self::GOLDEN_FIGURE => self::APPROVED_GOLDEN_FIGURE,
    ];

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::PRODUCT_VARIANT_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::GENERAL_PRICE,
        self::SPECIAL_PRICE,
        self::GOLDEN_FIGURE,
        self::LAST_UPDATED_AT,
    ];

    // ستون‌های پولی decimal هستند — cast float خطای نمایش IEEE-754 وارد می‌کرد
    protected $casts = [
        self::LAST_UPDATED_AT => 'datetime',
        self::GENERAL_PRICE => 'decimal:0',
        self::SPECIAL_PRICE => 'decimal:0',
        self::GOLDEN_FIGURE => 'decimal:0',
        self::APPROVED_GENERAL_PRICE => 'decimal:0',
        self::APPROVED_SPECIAL_PRICE => 'decimal:0',
        self::APPROVED_GOLDEN_FIGURE => 'decimal:0',
        self::APPROVED_PRICE_AT => 'datetime',
    ];

    /**
     * مبنای سنجشِ آستانه برای یک ستون.
     *
     * ردیفی که مبنایش خالی است (مثلاً ردیفِ تازه‌ای که هنوز `seedApprovedBaseline`
     * رویش نرفته) به قیمتِ فعلی برمی‌گردد — همان رفتارِ پیش از این تصمیم. سکوت
     * نمی‌کند و صفر هم نمی‌گیرد: صفر یعنی «تغییرِ بی‌نهایت» و هر تغییری را به صف
     * می‌فرستاد.
     */
    public function approvedBaselineFor(string $field): float
    {
        $column = self::APPROVED_BASELINE_OF[$field] ?? null;
        $baseline = $column !== null ? $this->{$column} : null;

        return (float) ($baseline ?? $this->{$field});
    }

    /**
     * مبنا را روی مقادیرِ فعلی می‌نشاند — **بدونِ ذخیره**.
     *
     * ⚠️ روی `creating` صدا زده می‌شود نه `created`. نسخه‌ی اول `created` بود و
     * داخلش `save()` می‌زد؛ آن یک نوشتنِ دومِ اضافه بود که روی کلیدِ یکتای
     * `(variant, factory, location)` می‌خورد و `create()` را ۵۰۰ می‌کرد. مقادیر
     * در `creating` از قبل ست شده‌اند، پس کپی‌کردنشان هیچ کوئریِ اضافه‌ای ندارد.
     */
    public function fillApprovedBaseline(): void
    {
        $this->forceFill([
            self::APPROVED_GENERAL_PRICE => $this->{self::GENERAL_PRICE},
            self::APPROVED_SPECIAL_PRICE => $this->{self::SPECIAL_PRICE},
            self::APPROVED_GOLDEN_FIGURE => $this->{self::GOLDEN_FIGURE},
            self::APPROVED_PRICE_AT => now(),
        ]);
    }

    /**
     * درخواستِ تغییرِ قیمتِ بازِ این ردیف (اگر هست).
     *
     * تا این رابطه پر است، واریانت از کاتالوگِ عمومی پنهان است و پنلِ
     * تأمین‌کننده «در انتظار تأیید تا …» نشان می‌دهد. `latestOfMany` چون یک
     * تأمین‌کننده می‌تواند پیش از تصمیمِ ادمین دوباره ثبت کند؛ آخری نمایش داده
     * می‌شود و گاردِ «پایه تکان خورده» موقعِ تأیید بقیه را می‌گیرد.
     *
     * @return HasOne<SupplierRequest, $this>
     */
    public function pendingPriceChange(): HasOne
    {
        return $this->hasOne(SupplierRequest::class, SupplierRequest::PRODUCT_PRICE_ID)
            ->pendingPriceChange()
            ->latestOfMany();
    }

    protected static function booted(): void
    {
        parent::booted();

        // ردیفِ تازه مبنای خودش است — تا اولین تأیید، همان چیزی که پذیرفته شده.
        static::creating(fn (ProductPrice $price) => $price->fillApprovedBaseline());
        static::created(fn (ProductPrice $price) => self::flushVariantCache($price));
        static::updated(fn (ProductPrice $price) => self::flushVariantCache($price));
        static::deleted(fn (ProductPrice $price) => self::flushVariantCache($price));
    }

    /**
     * پاکسازی هوشمند کش (سازگار با همه درایورها)
     */
    protected static function flushVariantCache(ProductPrice $price): void
    {
        if (! isset($price->product_variant_id)) {
            return;
        }
        $driver = config('cache.default');

        if (in_array($driver, ['redis', 'memcached'])) {
            try {
                Cache::tags(["variant:{$price->product_variant_id}"])->flush();

                return;
            } catch (\Exception $e) {
                Log::error('Failed to flush cache tags: '.$e->getMessage());
            }
        }

        // درایورهای بدون tag (file, database, ...) — حذف مستقیم کلید قیمت پایه؛
        // بدون این، قیمت کش‌شده تا یک ساعت بعد از تغییر قیمت کهنه می‌ماند.
        if (isset($price->factory_id, $price->factory_location_id)) {
            Cache::forget(SetBasePrice::cacheKey(
                (int) $price->product_variant_id,
                (int) $price->factory_id,
                (int) $price->factory_location_id,
            ));
        }
    }

    /* -------------------- Relationships -------------------- */

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, self::FACTORY_ID);
    }

    public function factoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, self::FACTORY_LOCATION_ID);
    }
}
