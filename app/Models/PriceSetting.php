<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::VALUE,
        self::DESCRIPTION,
    ];

    public static function getValue($code, $default = null)
    {
        $cacheKey = "price_setting_{$code}";

        return Cache::remember($cacheKey, 60 * 24, function () use ($code, $default) {
            $setting = self::where(self::CODE, $code)->first();

            return $setting ? $setting->{self::VALUE} : $default;
        });
    }

    public static function getValueAsFloat(string $code, float $default = 0.0): float
    {
        // از همان کشِ getValue استفاده می‌کنیم؛ قبلاً این متد روی هر محاسبهٔ VAT-inclusive
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
