<?php

namespace App\Models;

use App\Support\Totp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ورودِ دومرحله‌ای (TOTP) یک کاربر — مهاجرتِ `2026_09_14_170000` را ببینید.
 *
 * @property int $id
 * @property int $user_id
 * @property string $secret
 * @property Carbon|null $enabled_at
 * @property list<string>|null $recovery_codes
 * @property int|null $last_used_step
 */
class UserTwoFactor extends Model
{
    public const TABLE = 'user_two_factor';

    public const USER_ID = 'user_id';

    public const SECRET = 'secret';

    public const ENABLED_AT = 'enabled_at';

    public const RECOVERY_CODES = 'recovery_codes';

    public const LAST_USED_STEP = 'last_used_step';

    public const RECOVERY_CODE_COUNT = 10;

    /** بدونِ 0/O/1/I تا کدِ چاپ‌شده اشتباه خوانده نشود. */
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $table = self::TABLE;

    protected $fillable = [
        self::USER_ID,
        self::SECRET,
        self::ENABLED_AT,
        self::RECOVERY_CODES,
        self::LAST_USED_STEP,
    ];

    protected $hidden = [self::SECRET, self::RECOVERY_CODES];

    protected function casts(): array
    {
        return [
            self::SECRET => 'encrypted',
            self::ENABLED_AT => 'datetime',
            self::RECOVERY_CODES => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isEnabled(): bool
    {
        return $this->{self::ENABLED_AT} !== null;
    }

    /**
     * کدِ TOTP را می‌سنجد و در صورت پذیرش گامش را ثبت می‌کند تا دوباره پذیرفته نشود.
     */
    public function consumeTotp(string $code): bool
    {
        $step = Totp::verify($this->{self::SECRET}, $code);

        if ($step === null || ($this->{self::LAST_USED_STEP} !== null && $step <= $this->{self::LAST_USED_STEP})) {
            return false;
        }

        $this->forceFill([self::LAST_USED_STEP => $step])->save();

        return true;
    }

    /**
     * کدِ بازیابی را می‌سنجد و در صورت پذیرش همان یکی را حذف می‌کند (یک‌بارمصرف).
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $hash = self::hashRecoveryCode($code);
        $stored = $this->{self::RECOVERY_CODES} ?? [];

        foreach ($stored as $i => $candidate) {
            if (hash_equals($candidate, $hash)) {
                unset($stored[$i]);
                $this->forceFill([self::RECOVERY_CODES => array_values($stored)])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * ده کدِ تازه می‌سازد، هش‌شان را ذخیره می‌کند و متنِ خام را **یک بار** برمی‌گرداند.
     *
     * @return list<string>
     */
    public function issueRecoveryCodes(): array
    {
        $plain = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $chars = '';
            for ($j = 0; $j < 10; $j++) {
                $chars .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $plain[] = substr($chars, 0, 5).'-'.substr($chars, 5);
        }

        $this->forceFill([self::RECOVERY_CODES => array_map(self::hashRecoveryCode(...), $plain)])->save();

        return $plain;
    }

    private static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));
    }
}
