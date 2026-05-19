<?php

// app/Models/CartPriceFilterCategory.php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

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
