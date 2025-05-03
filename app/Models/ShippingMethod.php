<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingMethod extends Model
{
    const
        ID         = 'id',
        NAME       = 'name',
        CODE       = 'code',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::CODE,
    ];

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
