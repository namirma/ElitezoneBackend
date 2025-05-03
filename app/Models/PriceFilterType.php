<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceFilterType extends Model
{
    use HasFactory;

    public const
        ID         = 'id',
        NAME       = 'name',
        CODE       = 'code',
        APPLY_ON   = 'apply_on',
        IS_ACTIVE  = 'is_active',
        SORT_ORDER = 'sort_order',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::APPLY_ON,
        self::IS_ACTIVE,
        self::SORT_ORDER,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
    ];

    public function filters(): HasMany
    {
        return $this->hasMany(PriceFilter::class);
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    public function scopeForGeneral($query)
    {
        return $query->where(function($q) {
            $q->where(self::APPLY_ON, 'general')
              ->orWhere(self::APPLY_ON, 'both');
        });
    }

    public function scopeForSpecial($query)
    {
        return $query->where(function($q) {
            $q->where(self::APPLY_ON, 'special')
              ->orWhere(self::APPLY_ON, 'both');
        });
    }
}
