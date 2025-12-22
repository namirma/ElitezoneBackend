<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryPrediction extends Model
{
    public $timestamps = false;

    /* -------------------------------------------------------------------------- */
    /* Constants                                                                  */
    /* -------------------------------------------------------------------------- */
    const
        ID = 'id',
        PRODUCT_VARIANT_ID = 'product_variant_id',
        CURRENT_STOCK = 'current_stock',
        SALES_VELOCITY_30D = 'sales_velocity_30d',
        SEARCH_VOLUME_7D = 'search_volume_7d',
        PREDICTED_DEMAND_NEXT_7D = 'predicted_demand_next_7d',
        RECOMMENDED_RESTOCK_AMOUNT = 'recommended_restock_amount',
        PRIORITY = 'priority',
        REASON = 'reason',
        CALCULATED_AT = 'calculated_at';

    const
        PRIORITY_CRITICAL = 'critical', 
        PRIORITY_HIGH = 'high',
        PRIORITY_NORMAL = 'normal',
        PRIORITY_LOW = 'low';

    protected $fillable = [
        self::PRODUCT_VARIANT_ID,
        self::CURRENT_STOCK,
        self::SALES_VELOCITY_30D,
        self::SEARCH_VOLUME_7D,
        self::PREDICTED_DEMAND_NEXT_7D,
        self::RECOMMENDED_RESTOCK_AMOUNT,
        self::PRIORITY,
        self::REASON,
        self::CALCULATED_AT
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }
}
