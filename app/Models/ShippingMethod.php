<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingMethod extends Model
{
    const ID = 'id';

    const NAME = 'name';

    const CODE = 'code';

    const DELIVERY_NOTICE = 'delivery_notice';

    const DELIVERY_DAYS = 'delivery_days';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::DELIVERY_NOTICE,
        self::DELIVERY_DAYS,
    ];

    protected $casts = [
        self::DELIVERY_DAYS => 'integer',
    ];

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
