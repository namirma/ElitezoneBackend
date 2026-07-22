<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_variant_id
 * @property int $current_stock
 * @property numeric $sales_velocity_30d
 * @property int $search_volume_7d
 * @property numeric $predicted_demand_next_7d
 * @property numeric $recommended_restock_amount
 * @property string $priority
 * @property string|null $reason
 * @property string $calculated_at
 * @property-read ProductVariant $variant
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereCalculatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereCurrentStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction wherePredictedDemandNext7d($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereRecommendedRestockAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereSalesVelocity30d($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereSearchVolume7d($value)
 *
 * @mixin \Eloquent
 */
class InventoryPrediction extends Model
{
    public $timestamps = false;

    /* -------------------------------------------------------------------------- */
    /* Constants */
    /* -------------------------------------------------------------------------- */
    const ID = 'id';

    const PRODUCT_VARIANT_ID = 'product_variant_id';

    const CURRENT_STOCK = 'current_stock';

    const SALES_VELOCITY_30D = 'sales_velocity_30d';

    const SEARCH_VOLUME_7D = 'search_volume_7d';

    const PREDICTED_DEMAND_NEXT_7D = 'predicted_demand_next_7d';

    const RECOMMENDED_RESTOCK_AMOUNT = 'recommended_restock_amount';

    const PRIORITY = 'priority';

    const REASON = 'reason';

    const CALCULATED_AT = 'calculated_at';

    const PRIORITY_CRITICAL = 'critical';

    const PRIORITY_HIGH = 'high';

    const PRIORITY_NORMAL = 'normal';

    const PRIORITY_LOW = 'low';

    protected $fillable = [
        self::PRODUCT_VARIANT_ID,
        self::CURRENT_STOCK,
        self::SALES_VELOCITY_30D,
        self::SEARCH_VOLUME_7D,
        self::PREDICTED_DEMAND_NEXT_7D,
        self::RECOMMENDED_RESTOCK_AMOUNT,
        self::PRIORITY,
        self::REASON,
        self::CALCULATED_AT,
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }
}
