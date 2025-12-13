<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;

class OrderUnit extends Model
{
    use HasFactory;

    const
        ID = 'id',
        NAME = 'name',
        CODE = 'code',
        UNIT = 'unit',
        AMOUNT = 'amount',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::NAME,
        self::CODE,
        self::UNIT,
        self::AMOUNT,
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_order_units')
            ->withPivot('max_quantity')
            ->withTimestamps();
    }

    protected static function booted()
    {
        static::saved(function () {
            Cache::forget('pricing:order_units_collection');
        });

        static::deleted(function () {
            Cache::forget('pricing:order_units_collection');
        });
    }
}
