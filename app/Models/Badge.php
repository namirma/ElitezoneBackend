<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Badge extends Model
{
    use HasFactory;

    const ID = 'id';

    const NAME = 'name';

    const CODE = 'code';

    const ICON = 'icon';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::ICON,
    ];

    /**
     * Relationship to the price adjustments.
     */
    public function priceAdjustments(): HasMany
    {
        return $this->hasMany(PriceAdjustment::class);
    }

    /**
     * Relationship to the carts.
     */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }
}
