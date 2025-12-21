<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartEvent extends Model
{
    use HasFactory;

    const
        CART_ID             = 'cart_id',
        USER_ID             = 'user_id',
        SESSION_ID          = 'session_id',
        PRODUCT_VARIANT_ID  = 'product_variant_id',
        ACTION_TYPE         = 'action_type',
        QUANTITY_CHANGE     = 'quantity_change',
        UNIT_PRICE_SNAPSHOT = 'unit_price_snapshot';

    public const
        ACTION_ADD       = 'add',
        ACTION_REMOVE    = 'remove',
        ACTION_UPDATE    = 'update';

    protected $fillable = [
        self::CART_ID,
        self::USER_ID,
        self::SESSION_ID,
        self::PRODUCT_VARIANT_ID,
        self::ACTION_TYPE,
        self::QUANTITY_CHANGE,
        self::UNIT_PRICE_SNAPSHOT,
    ];
}
