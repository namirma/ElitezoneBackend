<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static create(array $array)
 */
class Listopher extends Model
{
    use HasFactory;

    const
        ID = 'id',
        PROJECT_CODE = 'project_code',
        TYPE = 'type';

    protected $fillable = [
        self::PROJECT_CODE,
        self::TYPE
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ListopherItem::class);
    }
}
