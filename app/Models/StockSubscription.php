<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockSubscription extends Model
{
    public const
        ID = 'id',
        USER_ID = 'user_id',
        PRODUCT_VARIANT_ID = 'product_variant_id',
        NOTIFIED_AT = 'notified_at',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::USER_ID,
        self::PRODUCT_VARIANT_ID,
        self::NOTIFIED_AT
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }
}
