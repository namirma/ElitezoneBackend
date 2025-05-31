<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PriceSetting extends Model
{
    use HasFactory;
    
    const
     ID = 'id',
     NAME = 'name',
     CODE = 'code',
     VALUE = 'value',
     DESCRIPTION = 'description',
     CREATED_AT = 'created_at',
     UPDATED_AT = 'updated_at',
     CODE_VAT_RATE = 'vat_rate',
     CODE_SHOW_PRICES_WITH_VAT = 'show_prices_with_vat';

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

    public static function getValueAsFloat($code, $default = 0.0)
    {
        return (float) self::getValue($code, $default);
    }

    public static function getValueAsInt($code, $default = 0)
    {
        return (int) self::getValue($code, $default);
    }

    public static function getValueAsBool($code, $default = false)
    {
        $value = self::getValue($code, $default);
        if (is_string($value)) {
            if (strtolower($value) === 'true') return true;
            if (strtolower($value) === 'false') return false;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
