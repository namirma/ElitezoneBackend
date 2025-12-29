<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSurvey extends Model
{
    use HasFactory;

    public const
        ID = 'id',
        ORDER_ID = 'order_id',
        USER_ID = 'user_id',
        RATING = 'rating',
        COMMENT = 'comment',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::ORDER_ID,
        self::USER_ID,
        self::RATING,
        self::COMMENT,
    ];

    protected $casts = [
        self::RATING => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }
}
