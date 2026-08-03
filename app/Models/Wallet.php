<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * نوع را دستی بازسازی کنند. طبق قاعدهٔ خودِ پروژه («relation را type کن»)، نوع
     * اینجا اعلام می‌شود نه در محلِ مصرف.
     *
     * ⚠️ روی شاخهٔ لاراول ۱۳ این تگ دو پارامتری است
     * (`HasMany<WalletTransaction, $this>`): لاراول ۱۱ به بعد `TDeclaringModel` را
     * اضافه کرده، ولی `HasMany` در لاراول ۱۰ فقط `TRelatedModel` دارد و فرمِ دو
     * پارامتری اینجا خطای PHPStan می‌دهد. این تنها تفاوتِ عمدیِ این فایل بین دو
     * شاخه است — موقع cherry-pick «اصلاحش» نکنید.
     *
     * @return HasMany<WalletTransaction>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
