<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int $order
 * @property int $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseParameterValue> $values
 * @property-read int|null $values_count
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup active()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup ordered()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup query()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PurchaseParameterGroup extends Model
{
    const ID = 'id';

    const NAME = 'name';

    const CODE = 'code';

    const DESCRIPTION = 'description';

    const ORDER = 'order';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::DESCRIPTION,
        self::ORDER,
        self::IS_ACTIVE,
    ];

    public function values(): HasMany
    {
        return $this->hasMany(PurchaseParameterValue::class, 'group_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
