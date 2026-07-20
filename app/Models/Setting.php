<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    const TABLE = 'settings';

    const ID = 'id';

    const KEY = 'key';

    const VALUE = 'value';

    const CACHE_KEY = 'settings:all';

    protected $table = self::TABLE;

    protected $fillable = [
        self::KEY,
        self::VALUE,
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * همه تنظیمات به صورت key => value (کش‌شده، TTL یک ساعت).
     */
    public static function allCached(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, fn () => self::query()
            ->pluck(self::VALUE, self::KEY)
            ->toArray());
    }
}
