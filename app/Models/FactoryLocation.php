<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $factory_id
 * @property string $name
 * @property string $type
 * @property string|null $city
 * @property string|null $address
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Factory $factory
 * @property-read Collection<int, ProductPrice> $prices
 * @property-read int|null $prices_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class FactoryLocation extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const FACTORY_ID = 'factory_id';

    public const NAME = 'name';

    public const TYPE = 'type';

    public const CITY = 'city';

    public const ADDRESS = 'address';

    public const IS_ACTIVE = 'is_active';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::FACTORY_ID,
        self::NAME,
        self::TYPE,
        self::CITY,
        self::ADDRESS,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
    ];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }
}
