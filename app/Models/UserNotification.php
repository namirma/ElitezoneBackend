<?php

namespace App\Models;

use App\Jobs\Notification\SendPushJob;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static where(array $array)
 * @method static create(array $array)
 */
class UserNotification extends Model
{
    use HasFactory;

    const ID = 'id';

    const USER_ID = 'user_id';

    const SENDER_ID = 'sender_id';

    const BATCH_ID = 'batch_id';

    const SUBJECT = 'subject';

    const BODY = 'body';

    const READ_AT = 'read_at';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        self::USER_ID,
        self::SENDER_ID,
        self::BATCH_ID,
        self::SUBJECT,
        self::BODY,
        self::READ_AT,
    ];

    /**
     * هر نوتیفیکیشنِ درون‌برنامه‌ای، یک پوش هم می‌شود.
     *
     * قلاب روی **مدل** است نه روی صداکننده‌ها: سه جا با `create()` ردیف
     * می‌سازند (`AuthController`، `EliteYARRelationController`،
     * `GenerateCsvReportJob`) و هر جای تازه هم بدون تغییرِ کد پوش می‌گیرد. اگر
     * به جایش در هر صداکننده یک dispatch می‌گذاشتیم، اولین صداکنندهٔ بعدی
     * فراموش می‌شد و کسی هم متوجه نمی‌شد.
     *
     * ⚠️ دو مسیرِ **انبوه** رویدادهای مدل را دور می‌زنند و عمداً پوش نمی‌فرستند:
     * `NotifyStockSubscribers` (`insert`) و `SendBulkNotificationJob`
     * (`insertOrIgnore`). ارسالِ گروهیِ ادمین احتمالاً باید پوش بدهد، ولی نه
     * با یک جاب به‌ازای هر ردیف — آن کار dispatchِ تکه‌تکه می‌خواهد و کارِ
     * جداگانه‌ای است.
     *
     * `afterCommit` لازم است: بدون آن، rollbackِ تراکنشِ میزبان نوتیفیکیشنی را
     * پوش می‌کرد که هرگز ذخیره نشد.
     */
    protected static function booted(): void
    {
        static::created(function (self $notification): void {
            SendPushJob::dispatch(
                (int) $notification->{self::USER_ID},
                (string) $notification->{self::SUBJECT},
                (string) $notification->{self::BODY},
                ['notification_id' => (int) $notification->{self::ID}],
            )->afterCommit();
        });
    }

    public function getCreatedAtAttribute()
    {
        $this->appends[] = 'created_at_p';
        $this->appends[] = 'created_at_diff';

        return $this->attributes['created_at'];
    }

    public function getUpdatedAtAttribute()
    {
        $this->appends[] = 'updated_at_p';
        $this->appends[] = 'updated_at_diff';

        return $this->attributes['updated_at'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
