<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $session_id
 * @property int|null $customer_type_id
 * @property int|null $badge_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Badge|null $badge
 * @property-read CustomerType|null $customerType
 * @property-read Collection<int, CartItem> $items
 * @property-read int|null $items_count
 * @property-read CartPriceFilterCategory|null $pivot
 * @property-read Collection<int, PriceFilterCategory> $priceFilterCategories
 * @property-read int|null $price_filter_categories_count
 * @property-read Collection<int, PurchaseParameterValue> $purchaseParameters
 * @property-read int|null $purchase_parameters_count
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereBadgeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereCustomerTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Cart extends Model
{
    use HasFactory;

    const ID = 'id';

    const USER_ID = 'user_id';

    const SESSION_ID = 'session_id';

    const CUSTOMER_TYPE_ID = 'customer_type_id';

    const BADGE_ID = 'badge_id';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::USER_ID,
        self::SESSION_ID,
        self::CUSTOMER_TYPE_ID,
        self::BADGE_ID,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function customerType(): BelongsTo
    {
        return $this->belongsTo(CustomerType::class);
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    public function priceFilterCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            PriceFilterCategory::class,
            'cart_price_filter_categories',
            'cart_id',
            'price_filter_category_id'
        )
            ->using(CartPriceFilterCategory::class)
            // فقط ستون‌های واقعی pivot
            ->withPivot(['cart_id', 'price_filter_category_id'])
            ->withTimestamps();
    }

    public function purchaseParameters(): BelongsToMany
    {
        return $this->belongsToMany(
            PurchaseParameterValue::class,
            'cart_purchase_parameter_value',
            'cart_id',
            'purchase_parameter_value_id'
        )->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
