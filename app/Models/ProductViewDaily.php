<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property \Illuminate\Support\Carbon $view_date
 * @property int $views
 * @property int $unique_sessions
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereUniqueSessions($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereViewDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereViews($value)
 * @mixin \Eloquent
 */
class ProductViewDaily extends Model
{
    protected $table = 'product_view_daily';

    const PRODUCT_ID = 'product_id';

    const VIEW_DATE = 'view_date';

    const VIEWS = 'views';

    const UNIQUE_SESSIONS = 'unique_sessions';

    protected $fillable = [
        self::PRODUCT_ID,
        self::VIEW_DATE,
        self::VIEWS,
        self::UNIQUE_SESSIONS,
    ];

    protected $casts = [
        self::PRODUCT_ID => 'integer',
        self::VIEW_DATE => 'date',
        self::VIEWS => 'integer',
        self::UNIQUE_SESSIONS => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, self::PRODUCT_ID);
    }
}
