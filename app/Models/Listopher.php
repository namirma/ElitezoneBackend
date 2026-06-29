<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static create(array $array)
 * @method static updateOrCreate(array $array, array $array1)
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
