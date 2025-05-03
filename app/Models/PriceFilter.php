<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PriceFilter extends Model
{
    use HasFactory;

    public const
        ID                    = 'id',
        PRICE_FILTER_TYPE_ID  = 'price_filter_type_id',
        NAME                  = 'name',
        CODE                  = 'code',
        AMOUNT                = 'amount',
        IS_PERCENTAGE         = 'is_percentage',
        IS_ACTIVE             = 'is_active',
        SORT_ORDER            = 'sort_order',
        CREATED_AT            = 'created_at',
        UPDATED_AT            = 'updated_at';

    protected $fillable = [
        self::PRICE_FILTER_TYPE_ID,
        self::NAME,
        self::CODE,
        self::AMOUNT,
        self::IS_PERCENTAGE,
        self::IS_ACTIVE,
        self::SORT_ORDER,
    ];

    protected $casts = [
        self::IS_PERCENTAGE => 'boolean',
        self::IS_ACTIVE     => 'boolean',
    ];

    public function filterType(): BelongsTo
    {
        return $this->belongsTo(PriceFilterType::class, self::PRICE_FILTER_TYPE_ID);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            PriceFilterCategory::class,
            'price_filter_category_items',
            self::ID,
            'price_filter_category_id'
        )
        ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    public function getFilterValue($baseAmount)
    {
        if ($this->is_percentage) {
            return $baseAmount * ($this->amount / 100);
        }
        return $this->amount;
    }
}
