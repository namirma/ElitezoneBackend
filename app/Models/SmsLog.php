<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * یک تلاشِ ارسالِ پیامک و نتیجه‌اش — نوشته‌شده توسط `SmsService::send()` و
 * `SendSmsJob` (برای ردهایی که هرگز به سرویس نمی‌رسند: خاموش، شرطِ ردشده).
 *
 * فقط شماره و متن — چیزی که سیستم از قبل دارد؛ هیچ داده‌ی شخصیِ تازه‌ای نه.
 */
class SmsLog extends Model
{
    use PersianDate;

    const TABLE = 'sms_logs';

    const ID = 'id';

    const MOBILE = 'mobile';

    const TEMPLATE_KEY = 'template_key';

    const BODY = 'body';

    const STATUS = 'status';

    const ERROR = 'error';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    /** تحویل به درگاه انجام شد. */
    const STATUS_SENT = 'sent';

    /** محیطِ غیرپروداکشن و شماره خارج از `TSMS_DEV_ALLOWLIST` — عمداً نرفت. */
    const STATUS_SKIPPED_ALLOWLIST = 'skipped_allowlist';

    /** قالب توسط ادمین خاموش است. */
    const STATUS_SKIPPED_INACTIVE = 'skipped_inactive';

    /** شرطِ ارسالِ قالب برقرار نبود (نقش / حداقل مبلغ). */
    const STATUS_SKIPPED_CONDITION = 'skipped_condition';

    /** TSMS پیکربندی نشده (لینک/شماره خالی). */
    const STATUS_UNCONFIGURED = 'unconfigured';

    /** درگاه خطا داد یا پاسخِ غیر-2xx برگرداند. */
    const STATUS_FAILED = 'failed';

    const STATUSES = [
        self::STATUS_SENT,
        self::STATUS_SKIPPED_ALLOWLIST,
        self::STATUS_SKIPPED_INACTIVE,
        self::STATUS_SKIPPED_CONDITION,
        self::STATUS_UNCONFIGURED,
        self::STATUS_FAILED,
    ];

    protected $table = self::TABLE;

    protected $fillable = [
        self::MOBILE,
        self::TEMPLATE_KEY,
        self::BODY,
        self::STATUS,
        self::ERROR,
    ];

    /**
     * ثبتِ یک تلاش. هرگز پرتاب نمی‌کند: لاگِ ارسال نباید خودِ ارسال (کد ورود!)
     * را بشکند — نه در پنجره‌ی گذارِ مهاجرت، نه روی دیسکِ پر.
     */
    public static function record(string $mobile, ?string $templateKey, string $body, string $status, ?string $error = null): void
    {
        try {
            static::query()->create([
                self::MOBILE => $mobile,
                self::TEMPLATE_KEY => $templateKey,
                self::BODY => $body,
                self::STATUS => $status,
                self::ERROR => $error === null ? null : mb_substr($error, 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SmsLog: ثبتِ لاگ پیامک ناموفق بود: '.$e->getMessage());
        }
    }
}
