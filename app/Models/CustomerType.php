<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerType extends Model
{
    const ID = 'id';

    const NAME = 'name';

    const CODE = 'code';

    const HAS_CONSULTANT = 'has_consultant';

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::HAS_CONSULTANT,
    ];

    public function priceAdjustments(): HasMany
    {
        return $this->hasMany(PriceAdjustment::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }
}
