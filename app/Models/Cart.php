<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
