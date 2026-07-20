<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    use HasFactory;
    use PersianDate;

    const TABLE = 'product_reviews';

    const ID = 'id';

    const USER_ID = 'user_id';

    const PRODUCT_ID = 'product_id';

    const RATING = 'rating';

    const COMMENT = 'comment';

    const STATUS = 'status';

    const REJECTION_REASON = 'rejection_reason';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_REJECTED = 'rejected';

    const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $table = self::TABLE;

    protected $fillable = [
        self::USER_ID,
        self::PRODUCT_ID,
        self::RATING,
        self::COMMENT,
        // STATUS و REJECTION_REASON عمداً fillable نیستند — فقط ادمین با forceFill
    ];

    protected $casts = [
        self::RATING => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, self::PRODUCT_ID);
    }
}
