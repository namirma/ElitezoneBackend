<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartEvent extends Model
{
    use HasFactory;

    const CART_ID = 'cart_id';

    const USER_ID = 'user_id';

    const SESSION_ID = 'session_id';

    const PRODUCT_VARIANT_ID = 'product_variant_id';

    const ACTION_TYPE = 'action_type';

    const QUANTITY_CHANGE = 'quantity_change';

    const UNIT_PRICE_SNAPSHOT = 'unit_price_snapshot';

    public const ACTION_ADD = 'add';

    public const ACTION_REMOVE = 'remove';

    public const ACTION_UPDATE = 'update';

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
