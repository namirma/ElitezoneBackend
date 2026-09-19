<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property numeric $balance
 * @property numeric $balance_hold
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, WalletTransaction> $transactions
 * @property-read int|null $transactions_count
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereBalance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereBalanceHold($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Wallet extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const USER_ID = 'user_id';

    public const BALANCE = 'balance';

    public const BALANCE_HOLD = 'balance_hold';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::USER_ID,
        self::BALANCE,
        self::BALANCE_HOLD,
    ];

    // ستون‌ها decimal(20,0) هستند — ریال بدون اعشار
    protected $casts = [
        self::BALANCE => 'decimal:0',
        self::BALANCE_HOLD => 'decimal:0',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    /**
     * بدونِ پارامترهای جنریک، larastan این را `HasMany<Model, Wallet>` می‌بیند و هر
     * چیزی که از آن بیرون می‌آید `Model` است — یعنی خواننده‌های دفتر مجبور می‌شوند
     * نوع را دستی بازسازی کنند. طبق قاعده‌ی خودِ پروژه («relation را type کن»)، نوع
     * اینجا اعلام می‌شود نه در محلِ مصرف.
     *
     * @return HasMany<WalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
