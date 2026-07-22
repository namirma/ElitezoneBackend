<?php

// app/Models/CartPriceFilterCategory.php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $cart_id
 * @property int $price_filter_category_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory whereCartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory wherePriceFilterCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class CartPriceFilterCategory extends Pivot
{
    // چون id نداریم
    public $incrementing = false;

    public $timestamps = true;

    protected $table = 'cart_price_filter_categories';

    protected $fillable = [
        'cart_id',
        'price_filter_category_id',
    ];
}
