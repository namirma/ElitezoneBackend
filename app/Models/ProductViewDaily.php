<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property Carbon $view_date
 * @property int $views
 * @property int $unique_sessions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereUniqueSessions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereViewDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereViews($value)
 *
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
