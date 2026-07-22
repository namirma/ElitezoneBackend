<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @method static create(array $array)
 * @method static where(string $string, string $string1)
 * @method static parentStatus($null)
 *
 * @property int $id
 * @property string $name
 * @property string|null $slug
 * @property int|null $parent_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, SupplierActivityCategory> $children
 * @property-read int|null $children_count
 * @property-read Collection<int, SupplierProfile> $suppliers
 * @property-read int|null $suppliers_count
 *
 * @method static Builder<static>|SupplierActivityCategory newModelQuery()
 * @method static Builder<static>|SupplierActivityCategory newQuery()
 * @method static Builder<static>|SupplierActivityCategory query()
 * @method static Builder<static>|SupplierActivityCategory whereCreatedAt($value)
 * @method static Builder<static>|SupplierActivityCategory whereId($value)
 * @method static Builder<static>|SupplierActivityCategory whereName($value)
 * @method static Builder<static>|SupplierActivityCategory whereParentId($value)
 * @method static Builder<static>|SupplierActivityCategory whereSlug($value)
 * @method static Builder<static>|SupplierActivityCategory whereUpdatedAt($value)
 * @method static Builder<static>|SupplierActivityCategory withoutChildren()
 *
 * @mixin \Eloquent
 */
class SupplierActivityCategory extends Model
{
    use HasFactory;

    const ID = 'id';

    const NAME = 'name';

    const SLUG = 'slug';

    const PARENT_ID = 'parent_id';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::SLUG,
        self::PARENT_ID,
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

    public function scopeParentStatus(Builder $query, ?int $value): void
    {
        $query->where(self::PARENT_ID, $value);
    }

    public static function getCacheKey(): string
    {
        return (new static)->getMorphClass().'_all';
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

    public function suppliers(): BelongsToMany
    {
        // Pivot table: supplier_activities(activity_category_id, supplier_id)
        return $this->belongsToMany(SupplierProfile::class, 'supplier_activities', 'activity_category_id', 'supplier_id');
    }

    public function scopeWithoutChildren($query)
    {
        return $query->without('children');
    }
}
