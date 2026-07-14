<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $cart_id
 * @property int|null $user_id
 * @property string|null $session_id
 * @property int $product_variant_id
 * @property string $action_type
 * @property numeric $quantity_change
 * @property numeric|null $unit_price_snapshot
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereActionType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereCartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereQuantityChange($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereUnitPriceSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereUserId($value)
 * @mixin \Eloquent
 */
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
