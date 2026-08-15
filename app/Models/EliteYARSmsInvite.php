<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دعوتِ پیامکیِ الیت یارِ ثبت‌نام‌نکرده، توسط کارفرما.
 *
 * قرینه‌ی `EmployerSmsInvite` (که الیت یار کارفرما را دعوت می‌کند). سقف و
 * چرخه‌ی عمر عیناً همان است تا دو سمتِ یک جریان رفتار متفاوت نداشته باشند.
 */
class EliteYARSmsInvite extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const EMPLOYER_ID = 'employer_id';

    public const MOBILE = 'mobile';

    public const SENT_AT = 'sent_at';

    /** حداکثر تعداد الیت یاری که هر کارفرما می‌تواند دعوت کند */
    public const MAX_INVITES = 10;

    /**
     * صریح، چون حدسِ لاراول از روی نامِ کلاس `elite_y_a_r_sms_invites` می‌شود:
     * `Str::snake` هر حرفِ بزرگ را یک مرزِ کلمه می‌بیند و `YAR` سه‌تا دارد.
     */
    protected $table = 'elite_yar_sms_invites';

    protected $fillable = [
        self::EMPLOYER_ID,
        self::MOBILE,
        self::SENT_AT,
    ];

    protected $casts = [
        self::SENT_AT => 'datetime',
    ];

    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, self::EMPLOYER_ID);
    }
}
