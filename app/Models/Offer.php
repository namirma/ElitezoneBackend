<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Offer extends Model
{
    public const
        ID = 'id',
        TITLE = 'title',
        DESCRIPTION = 'description',
        CODE = 'code',
        TYPE = 'type',
        VALUE = 'value',
        START_DATE = 'start_date',
        END_DATE = 'end_date',
        MINIMUM_QUANTITY = 'minimum_quantity',
        IS_ACTIVE = 'is_active',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::TITLE,
        self::DESCRIPTION,
        self::CODE,
        self::TYPE,
        self::VALUE,
        self::START_DATE,
        self::END_DATE,
        self::MINIMUM_QUANTITY,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::START_DATE => 'datetime',
        self::END_DATE => 'datetime',
        self::IS_ACTIVE => 'boolean',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_offers')
            ->withPivot('associated_at')
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    public function scopeCurrent($query)
    {
        return $query->active()
            ->where(function ($q) {
                $q->whereNull(self::START_DATE)
                    ->orWhere(self::START_DATE, '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull(self::END_DATE)
                    ->orWhere(self::END_DATE, '>=', now());
            });
    }
}
