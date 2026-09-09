<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * اشتراکِ پوشِ یک دستگاه. مالکِ ردیف با هر ثبتِ دوباره بازنویسی می‌شود —
 * دلیلش در docblock مهاجرتِ `create_device_tokens_table` آمده است.
 */
class DeviceToken extends Model
{
    use HasFactory;

    const ID = 'id';

    const USER_ID = 'user_id';

    const ENDPOINT = 'endpoint';

    const PUBLIC_KEY = 'public_key';

    const AUTH_KEY = 'auth_key';

    const PLATFORM = 'platform';

    const APP_VERSION = 'app_version';

    const LAST_SEEN_AT = 'last_seen_at';

    const PLATFORM_ANDROID = 'android';

    const PLATFORM_IOS = 'ios';

    const PLATFORM_WEB = 'web';

    /** @var list<string> */
    public const PLATFORMS = [
        self::PLATFORM_ANDROID,
        self::PLATFORM_IOS,
        self::PLATFORM_WEB,
    ];

    /** @var list<string> */
    protected $fillable = [
        self::USER_ID,
        self::ENDPOINT,
        self::PUBLIC_KEY,
        self::AUTH_KEY,
        self::PLATFORM,
        self::APP_VERSION,
        self::LAST_SEEN_AT,
    ];

    /** @var array<string, string> */
    protected $casts = [
        self::LAST_SEEN_AT => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
