<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $value
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
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
        $setting = self::where(self::CODE, $code)->first();

        if (! $setting) {
            return $default;
        }

        return (float) $setting->value;
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
