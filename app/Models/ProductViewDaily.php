<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
