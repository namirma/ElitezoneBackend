<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Badge extends Model
{
    use HasFactory;

    const
        ID = 'id',
        NAME = 'name',
        CODE = 'code',
        ICON = 'icon',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::ICON,
    ];

    /**
     * Relationship to the price adjustments.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function priceAdjustments(): HasMany
    {
        return $this->hasMany(PriceAdjustment::class);
    }

    /**
     * Relationship to the carts.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }
}
