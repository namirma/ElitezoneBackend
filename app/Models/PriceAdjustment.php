<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceAdjustment extends Model
{
    use HasFactory;

    const ID = 'id';

    const CUSTOMER_TYPE_ID = 'customer_type_id';

    const BADGE_ID = 'badge_id';

    const ADJUSTMENT_AMOUNT = 'adjustment_amount';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::CUSTOMER_TYPE_ID,
        self::BADGE_ID,
        self::ADJUSTMENT_AMOUNT,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::ADJUSTMENT_AMOUNT => 'float',
        self::IS_ACTIVE => 'boolean',
    ];

    public function customerType(): BelongsTo
    {
        return $this->belongsTo(CustomerType::class, self::CUSTOMER_TYPE_ID, CustomerType::ID);
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class, self::BADGE_ID, Badge::ID);
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }
}
