<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Offer extends Model
{
    public const ID = 'id';

    public const TITLE = 'title';

    public const DESCRIPTION = 'description';

    public const CODE = 'code';

    public const TYPE = 'type';

    public const VALUE = 'value';

    public const START_DATE = 'start_date';

    public const END_DATE = 'end_date';

    public const MINIMUM_QUANTITY = 'minimum_quantity';

    public const IS_ACTIVE = 'is_active';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

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
