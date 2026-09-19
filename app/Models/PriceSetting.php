<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $value
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $updated_by
 * @property-read User|null $updatedBy
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereValue($value)
 *
 * @mixin \Eloquent
 */
class PriceSetting extends Model
{
    use HasFactory;

    const ID = 'id';

    const NAME = 'name';

    const CODE = 'code';

    const VALUE = 'value';

    const DESCRIPTION = 'description';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    const CODE_VAT_RATE = 'vat_rate';

    const CODE_SHOW_PRICES_WITH_VAT = 'show_prices_with_vat';

    // نرخِ کارمزدِ تراکنش (%TC) — تنظیمِ سراسریِ قابل‌ویرایش (به‌جای هاردکد در سرویس تسویه).
    const CODE_TC_PERCENTAGE = 'tc_percentage';

    // سهمِ OW از %TC (درصد) — قبلاً ۴۰ هاردکد در SettlementCalculatorService::badgeShares.
    const CODE_OW_SHARE = 'ow_share_of_tc';

    // سهمِ CO از %TC به تفکیکِ نشان؛ SSG باقی‌مانده است (۱۰۰ − OW − CO).
    const CODE_CO_SHARE_GOLD = 'co_share_gold';

    const CODE_CO_SHARE_SILVER = 'co_share_silver';

    const CODE_CO_SHARE_BRONZE = 'co_share_bronze';

    const CODE_CO_SHARE_DEFAULT = 'co_share_default';

    const UPDATED_BY = 'updated_by';

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::VALUE,
        self::DESCRIPTION,
        self::UPDATED_BY,
    ];

    /**
     * فهرستِ پارامترهای مالیِ قابل‌مدیریت از پنل — تنها منبعِ برچسب/پیش‌فرض/بازه.
     *
     * `default` همان مقداری است که مصرف‌کننده‌ها در نبودِ ردیف استفاده می‌کنند
     * (SettlementCalculatorService / ApplyVat / ProductVariantResource)؛ نگهش
     * دار تا با آن‌ها یکی بماند.
     *
     * @return array<string, array{label:string, description:string, default:string, unit:string, min:float|null, max:float|null}>
     */
    public static function catalog(): array
    {
        $co = fn (string $badge, string $default) => [
            'label' => "سهم الیت یار — نشان {$badge}",
            'description' => "چند درصد از کارمزد تراکنش به الیت یارِ {$badge} می‌رسد. سهم سازه همان باقی‌مانده است: ۱۰۰ − سهم پلتفرم − این عدد.",
            'default' => $default, 'unit' => 'percent', 'min' => 0.0, 'max' => 100.0,
        ];

        return [
            self::CODE_TC_PERCENTAGE => [
                'label' => 'کارمزد تراکنش (%TC)',
                'description' => 'درصدی از مبنای فاکتور (کرایه + قیمت×وزن) که پلتفرم از هر سفارش برمی‌دارد. سوپلایر مبنا منهای همین درصد را می‌گیرد؛ خریدار فقط سهم پلتفرم را می‌پردازد.',
                'default' => '1', 'unit' => 'percent', 'min' => 0.0, 'max' => 20.0,
            ],
            self::CODE_VAT_RATE => [
                'label' => 'نرخ مالیات بر ارزش افزوده',
                'description' => 'روی قیمتِ نمایشی سایت و همه‌ی محاسبات تسویه اعمال می‌شود. هر سفارش نرخِ لحظه‌ی پرداختش را نگه می‌دارد؛ تغییر اینجا فقط سفارش‌های بعدی را می‌گیرد.',
                'default' => (string) config('financial.default_vat_rate', 10.0), 'unit' => 'percent', 'min' => 0.0, 'max' => 30.0,
            ],
            self::CODE_OW_SHARE => [
                'label' => 'سهم پلتفرم از کارمزد (OW)',
                'description' => 'چند درصد از کارمزد تراکنش سهم خودِ پلتفرم است. همین درصد است که واقعاً از خریدار گرفته می‌شود (مثلاً ۴۰٪ از ۱٪ = ۰٫۴٪ مبنا).',
                'default' => '40', 'unit' => 'percent', 'min' => 0.0, 'max' => 100.0,
            ],
            self::CODE_CO_SHARE_GOLD => $co('طلایی', '37.5'),
            self::CODE_CO_SHARE_SILVER => $co('نقره‌ای', '32.5'),
            self::CODE_CO_SHARE_BRONZE => $co('برنزی', '30'),
            self::CODE_CO_SHARE_DEFAULT => $co('عادی/پیشگام', '25'),
            self::CODE_SHOW_PRICES_WITH_VAT => [
                'label' => 'نمایش پیش‌فرض قیمت با ارزش افزوده',
                'description' => 'اگر روشن باشد، قیمت‌های کاتالوگ سایت به‌صورت پیش‌فرض با احتساب مالیات نشان داده می‌شوند.',
                'default' => 'false', 'unit' => 'boolean', 'min' => null, 'max' => null,
            ],
        ];
    }

    public static function getValue($code, $default = null)
    {
        $cacheKey = "price_setting_{$code}";

        // TTL: ۲۴ ساعت؛ booted() روی saved/deleted کش را باطل می‌کند.
        //
        // فقط مقدارِ ردیف کش می‌شود، نه $default: قبلاً پیش‌فرضِ *اولین* فراخوان
        // ۲۴ ساعت به همه‌ی فراخوان‌های بعدی داده می‌شد — یک probe با default=0
        // یک‌بار %TC را برای کلِ سیستم صفر کرد.
        $value = Cache::remember($cacheKey, 60 * 60 * 24, function () use ($code) {
            return self::where(self::CODE, $code)->value(self::VALUE);
        });

        return $value ?? $default;
    }

    public static function getValueAsFloat(string $code, float $default = 0.0): float
    {
        // از همان کشِ getValue استفاده می‌کنیم؛ قبلاً این متد روی هر محاسبه‌ی VAT-inclusive
        // یک کوئری مستقیم و بدون‌کش می‌زد، در حالی‌که یک مقدارِ تنظیماتیِ کم‌تغییر است.
        return (float) self::getValue($code, $default);
    }

    /**
     * ابطالِ کش هنگام تغییر/حذفِ تنظیمات تا مقادیر جدید (مثل نرخ VAT) فوراً اعمال شوند
     * و روی مقدارِ کش‌شده تا ۲۴ ساعت گیر نکنند.
     */
    protected static function booted(): void
    {
        $flush = function (self $setting): void {
            Cache::forget("price_setting_{$setting->{self::CODE}}");
        };

        static::saved($flush);
        static::deleted($flush);
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, self::UPDATED_BY);
    }

    public static function getValueAsInt($code, $default = 0)
    {
        return (int) self::getValue($code, $default);
    }

    public static function getValueAsBool($code, $default = false)
    {
        $value = self::getValue($code, $default);
        if (is_string($value)) {
            if (strtolower($value) === 'true') {
                return true;
            }
            if (strtolower($value) === 'false') {
                return false;
            }
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
