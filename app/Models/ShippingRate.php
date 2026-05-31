<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Freight rate for an origin (factory location) → destination (province / optional city).
 *
 * @see ShippingCostCalculator
 */
class ShippingRate extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const FACTORY_LOCATION_ID = 'factory_location_id';

    public const DESTINATION_PROVINCE_ID = 'destination_province_id';

    public const DESTINATION_CITY_ID = 'destination_city_id';

    public const FLAT_COST = 'flat_cost';

    public const PER_KG_COST = 'per_kg_cost';

    public const IS_ACTIVE = 'is_active';

    protected $fillable = [
        self::FACTORY_LOCATION_ID,
        self::DESTINATION_PROVINCE_ID,
        self::DESTINATION_CITY_ID,
        self::FLAT_COST,
        self::PER_KG_COST,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::FLAT_COST => 'decimal:2',
        self::PER_KG_COST => 'decimal:2',
        self::IS_ACTIVE => 'boolean',
    ];

    public function factoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, self::FACTORY_LOCATION_ID);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(self::IS_ACTIVE, true);
    }
}
