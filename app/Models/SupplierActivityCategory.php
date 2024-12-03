<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * @method static create(array $array)
 * @method static where(string $string, string $string1)
 * @method static parentStatus($null)
 */
class SupplierActivityCategory extends Model
{
    use HasFactory;

    const
        ID = 'id',
        NAME = 'name',
        SLUG = 'slug',
        PARENT_ID = 'parent_id',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::SLUG,
        self::PARENT_ID,
        self::CREATED_AT,
        self::UPDATED_AT,
    ];
    protected $with = ['children'];


    protected static function booted(): void
    {
        static::saved(function () {
            Cache::forget((new static)->getCacheKey());
        });

        static::deleted(function () {
            Cache::forget((new static)->getCacheKey());
        });

    }
    public function scopeParentStatus(Builder $query, int|null $value): void
    {
        $query->where(self::PARENT_ID, $value);
    }

    public static function getCacheKey(): string
    {
        return (new static)->getMorphClass() . '_all';
    }

    public static function getAll()
    {
        return Cache::rememberForever(self::getCacheKey(), function () {
            return self::parentStatus(null)->get();
        });
    }

    public function children(): HasMany
    {
        return $this->hasMany(SupplierActivityCategory::class, 'parent_id')->with('children');
    }
}
