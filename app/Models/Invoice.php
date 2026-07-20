<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public const STATUS_ISSUED = 'issued';

    public const STATUS_VOID = 'void';

    /**
     * ارتباط با سفارش
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * ارتباط با کاربر (خریدار یا تامین‌کننده)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
