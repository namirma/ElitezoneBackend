<?php

// app/Models/CartPriceFilterCategory.php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $cart_id
 * @property int $price_filter_category_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory whereCartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory wherePriceFilterCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory whereUpdatedAt($value)
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
