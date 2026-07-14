<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static create(array $array)
 * @method static updateOrCreate(array $array, array $array1)
 * @property int $id
 * @property string $project_code
 * @property string $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ListopherItem> $items
 * @property-read int|null $items_count
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher query()
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher whereProjectCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Listopher extends Model
{
    use HasFactory;

    const ID = 'id';

    const PROJECT_CODE = 'project_code';

    const TYPE = 'type';

    protected $fillable = [
        self::PROJECT_CODE,
        self::TYPE,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ListopherItem::class);
    }
}
