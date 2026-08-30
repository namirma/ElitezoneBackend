<?php

namespace App\Models;

use App\Enums\Shipping\ShippingCostBasis;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Freight rate for an origin (factory location) → destination (province / optional city).
 *
 * Cost is always `flat_cost + variable`, where the variable component depends on
 * {@see self::COST_BASIS}: 'weight' uses per_kg_cost × kg (e.g. rebar), 'count' uses
 * per_count_cost × sales-count (e.g. brick / wall-mesh). Each route picks its own basis.
 *
 * @see ShippingCostCalculator
 *
 * @property int $id
 * @property int $factory_location_id
 * @property int $destination_province_id
 * @property int|null $destination_city_id
 * @property numeric $flat_cost
 * @property numeric $per_kg_cost
 * @property ShippingCostBasis $cost_basis
 * @property numeric $per_count_cost
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read FactoryLocation $factoryLocation
 *
 * @method static Builder<static>|ShippingRate active()
 * @method static Builder<static>|ShippingRate newModelQuery()
 * @method static Builder<static>|ShippingRate newQuery()
 * @method static Builder<static>|ShippingRate query()
 * @method static Builder<static>|ShippingRate whereCostBasis($value)
 * @method static Builder<static>|ShippingRate whereCreatedAt($value)
 * @method static Builder<static>|ShippingRate whereDestinationCityId($value)
 * @method static Builder<static>|ShippingRate whereDestinationProvinceId($value)
 * @method static Builder<static>|ShippingRate whereFactoryLocationId($value)
 * @method static Builder<static>|ShippingRate whereFlatCost($value)
 * @method static Builder<static>|ShippingRate whereId($value)
 * @method static Builder<static>|ShippingRate whereIsActive($value)
 * @method static Builder<static>|ShippingRate wherePerCountCost($value)
 * @method static Builder<static>|ShippingRate wherePerKgCost($value)
 * @method static Builder<static>|ShippingRate whereUpdatedAt($value)
 *
 * @mixin \Eloquent
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

    public const COST_BASIS = 'cost_basis';

    public const PER_COUNT_COST = 'per_count_cost';

    public const IS_ACTIVE = 'is_active';

    protected $fillable = [
        self::FACTORY_LOCATION_ID,
        self::DESTINATION_PROVINCE_ID,
        self::DESTINATION_CITY_ID,
        self::FLAT_COST,
        self::PER_KG_COST,
        self::COST_BASIS,
        self::PER_COUNT_COST,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::FLAT_COST => 'decimal:0',
        self::PER_KG_COST => 'decimal:0',
        self::PER_COUNT_COST => 'decimal:0',
        self::COST_BASIS => ShippingCostBasis::class,
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
