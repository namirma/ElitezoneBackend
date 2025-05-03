<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PriceSetting extends Model
{
    protected $fillable = [
        'name', 'code', 'value', 'description'
    ];

    public static function getValue($code, $default = null)
    {
        $cacheKey = "price_setting_{$code}";

        return Cache::remember($cacheKey, 60 * 24, function () use ($code, $default) {
            $setting = self::where('code', $code)->first();
            return $setting ? $setting->value : $default;
        });
    }

    public static function getValueAsFloat($code, $default = 0)
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
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
