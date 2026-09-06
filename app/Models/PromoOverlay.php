<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * اورلیِ ترغیب/تبلیغاتی که ادمین از بک‌آفیس می‌سازد و ویرایش می‌کند.
 *
 * واژگانِ ثابتِ این کلاس (اکشنِ دکمه‌ها، نوعِ شرط‌ها، دوره‌ی تکرار) عمداً
 * محدود و شمارش‌شده است: فرانت باید بتواند هر مقدار را به رفتارِ مشخصی نگاشت
 * کند. اگر اینجا رشته‌ی آزاد می‌پذیرفتیم، ادمین می‌توانست اکشنی بنویسد که هیچ
 * کدی آن را نمی‌شناسد و دکمه بی‌صدا از کار می‌افتاد.
 */
class PromoOverlay extends Model
{
    use HasFactory;
    use PersianDate;
    use SoftDeletes;

    const TABLE = 'promo_overlays';

    const ID = 'id';

    const KEY = 'key';

    const TITLE = 'title';

    const BODY = 'body';

    const IMAGE = 'image';

    const BUTTONS = 'buttons';

    const AUDIENCE = 'audience';

    const ROUTES = 'routes';

    const DELAY_SECONDS = 'delay_seconds';

    const FREQUENCY = 'frequency';

    const FREQUENCY_TTL_HOURS = 'frequency_ttl_hours';

    const PRIORITY = 'priority';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /** کلیدهای پایدارِ سه اورلیِ سیستمی (seed می‌شوند، حذفشان مجاز نیست). */
    const KEY_WELCOME = 'welcome';

    const KEY_SELECT_ROLE = 'select_role';

    const KEY_SELECT_ELITE_YAR = 'select_elite_yar';

    const SYSTEM_KEYS = [
        self::KEY_WELCOME,
        self::KEY_SELECT_ROLE,
        self::KEY_SELECT_ELITE_YAR,
    ];

    // ── دوره‌ی تکرار ─────────────────────────────────────────────────────────
    /** فقط یک‌بار در کلِ عمرِ کاربر (روی همین مرورگر) */
    const FREQ_ONCE = 'once';

    /** روزی یک‌بار */
    const FREQ_DAY = 'day';

    /** یک‌بار در هر نشستِ مرورگر */
    const FREQ_SESSION = 'session';

    /** هر N ساعت یک‌بار (frequency_ttl_hours) */
    const FREQ_TTL = 'ttl';

    /** هر بار که شرایط احراز شد */
    const FREQ_ALWAYS = 'always';

    const FREQUENCIES = [
        self::FREQ_ONCE,
        self::FREQ_DAY,
        self::FREQ_SESSION,
        self::FREQ_TTL,
        self::FREQ_ALWAYS,
    ];

    // ── اکشنِ دکمه‌ها ────────────────────────────────────────────────────────
    /** رفتن به یک مسیرِ داخلی — value = مسیر (باید با / شروع شود) */
    const ACTION_NAVIGATE = 'navigate';

    /** فقط بستنِ اورلی */
    const ACTION_DISMISS = 'dismiss';

    /** باز کردنِ ورود/ثبت‌نام */
    const ACTION_AUTH = 'auth';

    /** باز کردنِ «اطلاعات بیشتر» — value = کلیدِ محتوا */
    const ACTION_MORE_INFO = 'more_info';

    /** لینکِ خارجی — value = آدرسِ http(s) */
    const ACTION_EXTERNAL = 'external';

    const BUTTON_ACTIONS = [
        self::ACTION_NAVIGATE,
        self::ACTION_DISMISS,
        self::ACTION_AUTH,
        self::ACTION_MORE_INFO,
        self::ACTION_EXTERNAL,
    ];

    /** ظاهرِ دکمه — نگاشتش به کلاس‌ها در فرانت است. */
    const BUTTON_STYLES = ['primary', 'secondary', 'link'];

    // ── انواعِ شرطِ مخاطب ─────────────────────────────────────────────────────
    /** مهمان یا واردشده — value: guest | authenticated */
    const RULE_AUTH_STATE = 'auth_state';

    /** نقشش را انتخاب کرده؟ — value: bool */
    const RULE_HAS_ROLE = 'has_role';

    /** نقشِ کاربر — value: آرایه‌ای از کلیدهای نقش */
    const RULE_PERSONA = 'persona';

    /** کارفرما الیت یارِ تأییدشده دارد؟ — value: bool */
    const RULE_HAS_ELITE_YAR = 'has_elite_yar';

    /** تعداد سفارش‌ها — operator + value عددی */
    const RULE_ORDERS_COUNT = 'orders_count';

    const RULE_TYPES = [
        self::RULE_AUTH_STATE,
        self::RULE_HAS_ROLE,
        self::RULE_PERSONA,
        self::RULE_HAS_ELITE_YAR,
        self::RULE_ORDERS_COUNT,
    ];

    const RULE_OPERATORS = ['=', '!=', '<', '<=', '>', '>='];

    // ── کلیدهای نقش (RULE_PERSONA) ───────────────────────────────────────────
    // واژگانِ رسمی «الیت یار» است. نامِ قدیمی `expert` هنوز **پذیرفته** می‌شود
    // (نه تولید) چون قاعده‌هایی که ادمین قبلاً ساخته آن را ذخیره کرده‌اند و
    // ردشان کردن یعنی هر ویرایشِ بعدیِ همان اورلی با ۴۲۲ شکست بخورد.
    const PERSONA_EMPLOYER = 'employer';

    const PERSONA_ELITE_YAR = 'elite_yar';

    const PERSONA_ELITE_YAR_EMPLOYER = 'elite_yar_and_employer';

    const PERSONA_SUPPLIER = 'supplier';

    /** کلیدهایی که پنل باید پیشنهاد بدهد (واژگانِ جدید). */
    const PERSONAS = [
        self::PERSONA_EMPLOYER,
        self::PERSONA_ELITE_YAR,
        self::PERSONA_ELITE_YAR_EMPLOYER,
        self::PERSONA_SUPPLIER,
    ];

    /** نامِ قدیمی → نامِ جدید. `OverlayAudienceEvaluator` با این ترجمه می‌کند. */
    const PERSONA_ALIASES = [
        'expert' => self::PERSONA_ELITE_YAR,
        'expert_and_employer' => self::PERSONA_ELITE_YAR_EMPLOYER,
    ];

    /** کلیدهایی که در اعتبارسنجی مجازند (جدید + قدیمی). */
    const PERSONAS_ACCEPTED = [
        self::PERSONA_EMPLOYER,
        self::PERSONA_ELITE_YAR,
        self::PERSONA_ELITE_YAR_EMPLOYER,
        self::PERSONA_SUPPLIER,
        'expert',
        'expert_and_employer',
    ];

    /**
     * نقش‌های Laratrust که «الیت یار بودن» را می‌رسانند.
     *
     * `elite_yar` نقشِ جدید است و بعد از مهاجرت به‌تنهایی کافی است؛
     * `engineer`/`contractor` برای کاربرانِ پیش از مهاجرت نگه داشته شده‌اند.
     */
    const ELITE_YAR_ROLES = ['elite_yar', 'engineer', 'contractor'];

    /** اسلاگ‌های UserType که «الیت یار بودن» را می‌رسانند (جدید + قدیمی). */
    const ELITE_YAR_SLUGS = ['elite_yar', 'expert'];

    /** کشِ فهرستِ فعال (پاسخِ عمومی). */
    const CACHE_KEY = 'content:promo_overlays:active';

    protected $table = self::TABLE;

    protected $fillable = [
        self::KEY,
        self::TITLE,
        self::BODY,
        self::IMAGE,
        self::BUTTONS,
        self::AUDIENCE,
        self::ROUTES,
        self::DELAY_SECONDS,
        self::FREQUENCY,
        self::FREQUENCY_TTL_HOURS,
        self::PRIORITY,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::BUTTONS => 'array',
        self::AUDIENCE => 'array',
        self::ROUTES => 'array',
        self::DELAY_SECONDS => 'integer',
        self::FREQUENCY_TTL_HOURS => 'integer',
        self::PRIORITY => 'integer',
        self::IS_ACTIVE => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
        static::restored(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** اورلیِ سیستمی است؟ (حذفش مجاز نیست — فقط غیرفعال‌سازی) */
    public function isSystem(): bool
    {
        return in_array($this->{self::KEY}, self::SYSTEM_KEYS, true);
    }
}
