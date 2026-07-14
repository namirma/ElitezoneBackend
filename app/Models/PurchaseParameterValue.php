<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $group_id
 * @property string $name
 * @property string $slug
 * @property string $code
 * @property string|null $description
 * @property int $order
 * @property int $is_active
 * @property int|null $price_filter_category_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\PurchaseParameterGroup $group
 * @property-read \App\Models\PriceFilterCategory|null $priceFilterCategory
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue wherePriceFilterCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PurchaseParameterValue extends Model
{
    const ID = 'id';

    const GROUP_ID = 'group_id';

    const NAME = 'name';

    const SLUG = 'slug';

    const CODE = 'code';

    const DESCRIPTION = 'description';

    const ORDER = 'order';

    const IS_ACTIVE = 'is_active';

    const PRICE_FILTER_CATEGORY_ID = 'price_filter_category_id';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::GROUP_ID,
        self::NAME,
        self::SLUG,
        self::CODE,
        self::DESCRIPTION,
        self::ORDER,
        self::IS_ACTIVE,
        self::PRICE_FILTER_CATEGORY_ID,
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(PurchaseParameterGroup::class, 'group_id');
    }

    public function priceFilterCategory(): BelongsTo
    {
        return $this->belongsTo(PriceFilterCategory::class, 'price_filter_category_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_purchase_parameters');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
