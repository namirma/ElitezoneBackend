<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
