<?php

namespace App\Models;

use App\Sms\EventCatalog;
use App\Sms\PlaceholderCatalog;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * یک پیامکِ سیستم — متنش، شرطِ ارسالش، و اینکه اصلاً روشن است یا نه.
 *
 * ## چرا جدول، وقتی `lang/fa/sms.php` هست
 *
 * متنِ lang فقط با deploy عوض می‌شود و ادمین نه می‌بیندش نه می‌تواند خاموشش کند.
 * این جدول همان کلیدها را به رکورد تبدیل می‌کند؛ **نبودِ ردیف یعنی متنِ lang**،
 * پس تا پیش از seed هیچ رفتاری عوض نمی‌شود، و حذفِ ردیف هم پیامک را نمی‌کُشد.
 *
 * ## دو نقطه‌ی ورود — عمداً دو تا
 *
 * - `render()` → متنِ نهایی برای **ارسال**، یا `null` اگر نباید برود (خاموش،
 *   یا شرط رد شد). `SendSmsJob` فقط این را می‌خواند.
 * - `text()` → همان متن **بدونِ** شرط و فعال‌بودن — برای بدنه‌ی اعلانِ زنگوله
 *   (`Notifier`) و ارسالِ آزمایشیِ ادمین. خاموش‌کردنِ پیامک نباید اعلانِ
 *   درون‌برنامه‌ای را هم خفه کند؛ آن کانالِ دیگری است.
 *
 * ## شرط‌ها (`conditions`) — واژگانِ کوچک، اعتبارسنجی در FormRequest
 *
 * - `roles: [..]`   گیرنده باید یکی از این نقش‌ها را داشته باشد. گیرنده از روی
 *                    شماره پیدا می‌شود؛ شماره‌ای که کاربر نیست (راننده، دعوت‌شده)
 *                    از این شرط **معاف** است، نه رد.
 * - `min_amount: N` فقط وقتی پارامترِ `amount` هست؛ `number_format` را می‌فهمد.
 * - `quiet_hours: {from, to}` پیامک دور انداخته نمی‌شود، **به تعویق می‌افتد**
 *                    تا پایانِ پنجره (`quietHoursDelay()`)، به وقتِ تهران.
 *
 * ## کلیدهای حفاظت‌شده
 *
 * کد ورود، تأیید راننده و تأیید ذی‌نفع = احراز هویت. خاموش‌کردن یا شرط‌گذاشتن
 * رویشان یعنی قطعِ ورود؛ FormRequest هر دو را ۴۲۲ می‌کند و `render()` هم
 * برایشان شرط اجرا نمی‌کند (دفاعِ دوم — اگر ردیف مستقیم دستکاری شد).
 */
class SmsTemplate extends Model
{
    const TABLE = 'sms_templates';

    const ID = 'id';

    const KEY = 'key';

    const TITLE = 'title';

    const TRIGGER_DESCRIPTION = 'trigger_description';

    const AUDIENCE = 'audience';

    const BODY = 'body';

    const PLACEHOLDERS = 'placeholders';

    const IS_ACTIVE = 'is_active';

    const IS_PROTECTED = 'is_protected';

    const CONDITIONS = 'conditions';

    const EVENT = 'event';

    const TRIGGER_KIND = 'trigger_kind';

    const SENDER = 'sender';

    const SCHEDULE = 'schedule';

    const IS_CUSTOM = 'is_custom';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    const AUDIENCE_BUYER = 'buyer';

    const AUDIENCE_SUPPLIER = 'supplier';

    const AUDIENCE_ELITE_YAR = 'elite_yar';

    const AUDIENCE_EMPLOYER = 'employer';

    const AUDIENCE_DRIVER = 'driver';

    const AUDIENCE_ADMIN = 'admin';

    const AUDIENCE_ANY = 'any';

    /** محرک: داخلِ یک رویدادِ دامنه‌ای، یک اقدام/کلاسِ مشخص، یک کرون، یا (هنوز) هیچ‌جا. */
    const KIND_EVENT = 'event';

    const KIND_ACTION = 'action';

    const KIND_SCHEDULED = 'scheduled';

    const KIND_NONE = 'none';

    const KINDS = [self::KIND_EVENT, self::KIND_ACTION, self::KIND_SCHEDULED, self::KIND_NONE];

    const AUDIENCES = [
        self::AUDIENCE_BUYER,
        self::AUDIENCE_SUPPLIER,
        self::AUDIENCE_ELITE_YAR,
        self::AUDIENCE_EMPLOYER,
        self::AUDIENCE_DRIVER,
        self::AUDIENCE_ADMIN,
        self::AUDIENCE_ANY,
    ];

    /** کلیدهایی که خاموش‌شدنشان یعنی هیچ‌کس نمی‌تواند وارد شود. */
    const PROTECTED_KEYS = ['loginMessage', 'driver_verify', 'beneficiary_verification'];

    /*
     * دسته‌ی قالب — «استاندارد لحن و نگارش پیامک‌ها»ی مدیریت، بندِ ۱۸ (دورِ ششم، ۱۴۰۵/۰۷/۱۵):
     * «تمام SMS Templateها باید حداقل در یکی از دسته‌های زیر قرار گیرند.»
     */
    const CATEGORY = 'category';

    const CATEGORY_AUTH = 'AUTH';

    const CATEGORY_TRANSACTION = 'TRANSACTION';

    const CATEGORY_STATUS = 'STATUS';

    const CATEGORY_ACTION_REQUIRED = 'ACTION_REQUIRED';

    const CATEGORY_REMINDER = 'REMINDER';

    const CATEGORY_SECURITY = 'SECURITY';

    const CATEGORY_SUPPORT = 'SUPPORT';

    const CATEGORY_MARKETING = 'MARKETING';

    /** @var array<string, string> دسته => برچسبِ فارسی (همان توضیحِ دستورالعمل) */
    const CATEGORIES = [
        self::CATEGORY_AUTH => 'احراز هویت و کد یک‌بارمصرف',
        self::CATEGORY_TRANSACTION => 'ثبت درخواست، سفارش، پرداخت و عملیات اصلی',
        self::CATEGORY_STATUS => 'تغییر وضعیت درخواست یا سفارش',
        self::CATEGORY_ACTION_REQUIRED => 'نیازمند اقدام کاربر',
        self::CATEGORY_REMINDER => 'یادآوری',
        self::CATEGORY_SECURITY => 'ورود، تغییر اطلاعات حساس یا هشدار امنیتی',
        self::CATEGORY_SUPPORT => 'پشتیبانی',
        self::CATEGORY_MARKETING => 'بازاریابی (جدا از پیام‌های عملیاتی)',
    ];

    /** @var array<string, string> کلیدِ قالبِ سیستمی => دسته */
    private const CATEGORY_BY_KEY = [
        'loginMessage' => self::CATEGORY_AUTH,
        'beneficiary_verification' => self::CATEGORY_AUTH,
        'driver_verify' => self::CATEGORY_AUTH,
        'newRegisterUser' => self::CATEGORY_TRANSACTION,
        'wallet_funded' => self::CATEGORY_TRANSACTION,
        'wallet_deposited_podium' => self::CATEGORY_TRANSACTION,
        'order_paid_buyer' => self::CATEGORY_TRANSACTION,
        'order_paid_supplier' => self::CATEGORY_TRANSACTION,
        'buyer_refund_released' => self::CATEGORY_TRANSACTION,
        'order_cancelled_refunded' => self::CATEGORY_TRANSACTION,
        'elite_yar_verified' => self::CATEGORY_STATUS,
        'elite_yar_hold_employer' => self::CATEGORY_STATUS,
        'elite_yar_restored_employer' => self::CATEGORY_STATUS,
        'cart_payment_link_cancelled' => self::CATEGORY_STATUS,
        'transaction_confirmed' => self::CATEGORY_STATUS,
        'transaction_rejected' => self::CATEGORY_STATUS,
        'order_cancelled' => self::CATEGORY_STATUS,
        'cart_prices_updated' => self::CATEGORY_STATUS,
        'overweight_rejected' => self::CATEGORY_STATUS,
        'delivery_confirmed' => self::CATEGORY_STATUS,
        'shipment_returned' => self::CATEGORY_STATUS,
        'return_damage_settled' => self::CATEGORY_STATUS,
        'return_damage_cap_reached' => self::CATEGORY_STATUS,
        'reserve_extension_accepted' => self::CATEGORY_STATUS,
        'reserve_extension_rejected' => self::CATEGORY_STATUS,
        'order_cancel_accepted' => self::CATEGORY_STATUS,
        'order_cancel_rejected' => self::CATEGORY_STATUS,
        'order_cancel_escalated' => self::CATEGORY_STATUS,
        'order_cancel_admin_approved' => self::CATEGORY_STATUS,
        'order_cancel_admin_rejected' => self::CATEGORY_STATUS,
        'price_change_expired' => self::CATEGORY_STATUS,
        'product_change_approved' => self::CATEGORY_STATUS,
        'product_change_rejected' => self::CATEGORY_STATUS,
        'supplier_approved' => self::CATEGORY_STATUS,
        'supplier_rejected' => self::CATEGORY_STATUS,
        'coupon_funding_accepted' => self::CATEGORY_STATUS,
        'coupon_funding_declined' => self::CATEGORY_STATUS,
        'elite_yar_unverified' => self::CATEGORY_ACTION_REQUIRED,
        'elite_yar_invitation' => self::CATEGORY_ACTION_REQUIRED,
        'elite_yar_sms_invitation' => self::CATEGORY_ACTION_REQUIRED,
        'employer_invitation' => self::CATEGORY_ACTION_REQUIRED,
        'cart_payment_link' => self::CATEGORY_ACTION_REQUIRED,
        'employer_account_claim' => self::CATEGORY_ACTION_REQUIRED,
        'coupon_wallet_low_admin' => self::CATEGORY_ACTION_REQUIRED,
        'order_placed' => self::CATEGORY_ACTION_REQUIRED,
        'supplier_debt_notification' => self::CATEGORY_ACTION_REQUIRED,
        'overweight_supplier_request' => self::CATEGORY_ACTION_REQUIRED,
        'overweight_buyer_request' => self::CATEGORY_ACTION_REQUIRED,
        'driver_assigned' => self::CATEGORY_ACTION_REQUIRED,
        'driver_arrived' => self::CATEGORY_ACTION_REQUIRED,
        'return_damage_awaiting_buyer' => self::CATEGORY_ACTION_REQUIRED,
        'reserve_extension_proposed' => self::CATEGORY_ACTION_REQUIRED,
        'order_cancel_requested' => self::CATEGORY_ACTION_REQUIRED,
        'supplier_needs_correction' => self::CATEGORY_ACTION_REQUIRED,
        'coupon_funding_requested' => self::CATEGORY_ACTION_REQUIRED,
        'cart_expiring' => self::CATEGORY_REMINDER,
        'delivery_expiring' => self::CATEGORY_REMINDER,
        'ticket_admin_replied' => self::CATEGORY_SUPPORT,
        'ticket_resolved' => self::CATEGORY_SUPPORT,
        'ticket_closed' => self::CATEGORY_SUPPORT,
    ];

    public static function categoryFor(string $key): ?string
    {
        return self::CATEGORY_BY_KEY[$key] ?? null;
    }

    /** جانگهدارهایی که عدد نمایشی‌اند و با ارقامِ فارسی نوشته می‌شوند (دستورالعملِ پیامک، بندِ ۱۵). */
    private const PERSIAN_DIGIT_PARAMS = ['amount', 'balance', 'minutes', 'hours', 'percent'];

    const COND_ROLES = 'roles';

    const COND_MIN_AMOUNT = 'min_amount';

    const COND_QUIET_HOURS = 'quiet_hours';

    /** نقش‌های Laratrust که شرطِ `roles` می‌پذیرد. */
    const ROLE_OPTIONS = ['engineer', 'contractor', 'employer', 'supplier', 'admin'];

    const CACHE_KEY = 'sms_templates:by_key';

    /** ادمین «۲۲ تا ۸» را به وقتِ ایران می‌نویسد؛ `app.timezone` UTC است. */
    const QUIET_HOURS_TIMEZONE = 'Asia/Tehran';

    /** کلیدِ سفارشی: حروف/عدد/زیرخط، تا ۱۰۰ کاراکتر — همان شکلِ کلیدهای lang. */
    const KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{1,99}$/';

    protected $table = self::TABLE;

    protected $fillable = [
        self::KEY,
        self::TITLE,
        self::TRIGGER_DESCRIPTION,
        self::AUDIENCE,
        self::BODY,
        self::PLACEHOLDERS,
        self::IS_ACTIVE,
        self::CONDITIONS,
        self::EVENT,
        self::TRIGGER_KIND,
        self::SENDER,
        self::SCHEDULE,
        self::IS_CUSTOM,
        self::CATEGORY,
    ];

    protected $casts = [
        self::PLACEHOLDERS => 'array',
        self::CONDITIONS => 'array',
        self::IS_ACTIVE => 'boolean',
        self::IS_PROTECTED => 'boolean',
        self::IS_CUSTOM => 'boolean',
    ];

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget(self::CACHE_KEY);

        static::saved($flush);
        static::deleted($flush);
    }

    // ── خواندن ──────────────────────────────────────────────────────────────

    /** @return array<string, self> */
    public static function allCached(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            // پنجره‌ی گذارِ deploy/تست: تا مهاجرت اجرا نشده، رفتار همان lang است —
            // ولی با هشدار، نه بی‌صدا. کدِ ورود نباید به‌خاطر یک migrate جامانده بمیرد.
            if (! Schema::hasTable(self::TABLE)) {
                Log::warning('SmsTemplate: جدول sms_templates وجود ندارد؛ متن‌ها از lang خوانده می‌شوند. php artisan migrate را اجرا کنید.');

                return [];
            }

            return static::query()->get()->keyBy(self::KEY)->all();
        });
    }

    public static function findCached(string $key): ?self
    {
        return static::allCached()[$key] ?? null;
    }

    public static function isProtectedKey(string $key): bool
    {
        return in_array($key, self::PROTECTED_KEYS, true);
    }

    /**
     * جانگهدارهایی که متنِ این قالب حق دارد داشته باشد: سفارشی از رویدادش،
     * built-in از فراداده‌ی seeder. یک منبع برای Resource، اعتبارسنجی و ارسالِ آزمایشی.
     *
     * @return list<string>
     */
    public function allowedPlaceholders(): array
    {
        if ($this->{self::IS_CUSTOM}) {
            return EventCatalog::all()[(string) $this->{self::EVENT}]['placeholders'] ?? [];
        }

        return array_values((array) ($this->{self::PLACEHOLDERS} ?? []));
    }

    /**
     * همان جانگهدارها، با معنی و نمونه — برای پنل.
     *
     * @return list<array{name: string, meaning: string, example: string}>
     */
    public function placeholderDetails(): array
    {
        return PlaceholderCatalog::describe($this->allowedPlaceholders(), (string) $this->{self::KEY});
    }

    /**
     * هر کلیدِ `lang/fa/sms.php` یک ردیف.
     *
     * با فهرستِ فراداده (seeder): عنوان/شرح/مخاطب/جانگهدارها/حفاظت به‌روز می‌شوند،
     * ولی متن، فعال‌بودن و شرط‌های ردیفِ موجود — که مالِ ادمین‌اند — دست نمی‌خورند.
     * بدونِ فهرست (بازشدنِ صفحه‌ی پنل): فقط ردیفِ **غایب** ساخته می‌شود، با عنوانِ
     * خودِ کلید — تا کلیدی که نشستِ دیگری همین امروز اضافه کرده از پنل جا نماند.
     *
     * عنصرِ پنجم، محرکِ ساخت‌یافته: `[kind, eventOrSender, schedule?]` —
     * برای `event` عنصرِ دوم کلاسِ رویداد است و در ستونِ `event` می‌نشیند؛ برای
     * بقیه کلاس/متد یا نامِ دستور است و در `sender` می‌نشیند.
     *
     * @param  array<string, array{0: string, 1: string, 2: string, 3: list<string>, 4?: array{0: string, 1?: string|null, 2?: string|null}}>  $catalog
     */
    public static function syncFromLang(array $catalog = []): void
    {
        $lang = trans('sms');

        if (! is_array($lang)) {
            return;
        }

        $existing = static::query()->get()->keyBy(self::KEY);

        foreach ($lang as $key => $body) {
            if (! is_string($body)) {
                continue;
            }

            $meta = $catalog[$key] ?? null;
            $row = $existing[$key] ?? null;

            if ($row !== null && $meta === null) {
                continue;
            }

            $row ??= new self([self::KEY => $key, self::BODY => $body, self::IS_ACTIVE => true]);

            [$kind, $target, $schedule] = array_pad((array) ($meta[4] ?? []), 3, null);

            $row->fill([
                self::TITLE => $meta[0] ?? $key,
                self::TRIGGER_DESCRIPTION => $meta[1] ?? null,
                self::AUDIENCE => $meta[2] ?? self::AUDIENCE_ANY,
                self::PLACEHOLDERS => $meta[3] ?? static::placeholdersIn($body),
                self::TRIGGER_KIND => $kind,
                self::EVENT => $kind === self::KIND_EVENT ? $target : null,
                self::SENDER => $kind === self::KIND_EVENT ? null : $target,
                self::SCHEDULE => $schedule,
                self::IS_CUSTOM => false,
                // دسته‌ای که ادمین گذاشته می‌ماند؛ خالی ⇒ دسته‌ی پیش‌فرضِ کلید.
                self::CATEGORY => $row->{self::CATEGORY} ?? static::categoryFor((string) $key),
            ]);
            // عمداً fillable نیست — فقط همین‌جا نوشته می‌شود، نه از بدنه‌ی درخواست.
            $row->{self::IS_PROTECTED} = static::isProtectedKey($key);

            if ($row->isDirty()) {
                $row->save();
            }
        }
    }

    // ── رندر ────────────────────────────────────────────────────────────────

    /**
     * متنِ نهایی برای ارسال — یا `null` اگر این پیامک نباید برود.
     *
     * @param  array<string, mixed>  $params
     */
    public static function render(string $key, array $params = [], ?string $mobile = null): ?string
    {
        $template = static::findCached($key);
        $params = self::withFirstName($template !== null ? (string) $template->{self::BODY} : (string) __("sms.{$key}"), $params, $mobile);

        if ($template === null) {
            return self::fromLang($key, $params);
        }

        if (! $template->{self::IS_ACTIVE}) {
            return null;
        }

        if (! $template->conditionsPass($params, $mobile)) {
            return null;
        }

        return static::interpolate((string) $template->{self::BODY}, $params);
    }

    /**
     * همان متن، بدونِ شرط و فعال‌بودن — برای اعلانِ زنگوله و ارسالِ آزمایشی.
     *
     * @param  array<string, mixed>  $params
     */
    public static function text(string $key, array $params = []): ?string
    {
        $template = static::findCached($key);

        return $template === null
            ? self::fromLang($key, $params)
            : self::interpolate((string) $template->{self::BODY}, $params);
    }

    /**
     * اگر الان داخلِ پنجره‌ی سکوتِ این قالب هستیم، لحظه‌ی پایانِ پنجره؛ وگرنه null.
     */
    public static function quietHoursDelay(string $key, ?Carbon $now = null): ?Carbon
    {
        $window = static::findCached($key)?->{self::CONDITIONS}[self::COND_QUIET_HOURS] ?? null;

        if (! is_array($window) || empty($window['from']) || empty($window['to'])) {
            return null;
        }

        $now = ($now ?? Carbon::now())->copy()->setTimezone(self::QUIET_HOURS_TIMEZONE);
        $from = $now->copy()->setTimeFromTimeString((string) $window['from']);
        $to = $now->copy()->setTimeFromTimeString((string) $window['to']);

        // پنجره‌ی درونِ یک روز (۱۳ تا ۱۴)
        if ($from->lte($to)) {
            return $now->between($from, $to) ? $to : null;
        }

        // پنجره‌ی شبانه (۲۲ تا ۸): یا امشب شروع شده، یا از دیشب ادامه دارد
        if ($now->gte($from)) {
            return $to->addDay();
        }

        return $now->lt($to) ? $to : null;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function conditionsPass(array $params, ?string $mobile): bool
    {
        $conditions = $this->{self::CONDITIONS};

        if (! is_array($conditions) || $conditions === [] || $this->{self::IS_PROTECTED}) {
            return true;
        }

        $roles = $conditions[self::COND_ROLES] ?? null;

        if (is_array($roles) && $roles !== [] && $mobile !== null) {
            $user = User::query()->where(User::MOBILE, PhoneNumber::to09($mobile))->first();

            if ($user instanceof User && ! $user->hasRole($roles)) {
                return false;
            }
        }

        $minAmount = $conditions[self::COND_MIN_AMOUNT] ?? null;

        if ($minAmount !== null && $minAmount !== '' && array_key_exists('amount', $params)) {
            // `number_format` رشته‌ی «۱,۲۰۰,۰۰۰» می‌دهد؛ فقط رقم‌ها مقایسه می‌شوند.
            $amount = (float) preg_replace('/[^\d.]/', '', (string) $params['amount']);

            if ($amount < (float) $minAmount) {
                return false;
            }
        }

        return true;
    }

    /**
     * شرط‌های خالی را دور می‌ریزد: `{roles: [], min_amount: "", quiet_hours: null}`
     * یعنی «بدون شرط» و باید `null` ذخیره شود، نه یک شیءِ تهی که بعداً کسی
     * بخواهد تفسیرش کند.
     *
     * @return array<string, mixed>|null
     */
    public static function normalizeConditions(mixed $conditions): ?array
    {
        if (! is_array($conditions)) {
            return null;
        }

        $out = [];

        $roles = $conditions[self::COND_ROLES] ?? null;
        if (is_array($roles) && $roles !== []) {
            $out[self::COND_ROLES] = array_values(array_unique(array_map('strval', $roles)));
        }

        $min = $conditions[self::COND_MIN_AMOUNT] ?? null;
        if ($min !== null && $min !== '' && (int) $min > 0) {
            $out[self::COND_MIN_AMOUNT] = (int) $min;
        }

        $window = $conditions[self::COND_QUIET_HOURS] ?? null;
        if (is_array($window) && ! empty($window['from']) && ! empty($window['to'])) {
            $out[self::COND_QUIET_HOURS] = ['from' => (string) $window['from'], 'to' => (string) $window['to']];
        }

        return $out === [] ? null : $out;
    }

    /**
     * فهرستِ `:placeholder`های داخلِ یک متن — برای نمایش به ادمین و اعتبارسنجی.
     *
     * @return list<string>
     */
    public static function placeholdersIn(string $body): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/u', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * جایگزینیِ `:name` — همان قاعده‌ی مترجمِ لاراول: کلیدهای بلندتر اول، تا
     * `:order_number` پیش از `:order` جایگزین شود.
     *
     * @param  array<string, mixed>  $params
     */
    public static function interpolate(string $body, array $params): string
    {
        // دستورالعملِ پیامکِ مدیریت (دورِ ششم، پرسشِ ۷؛ docs/specs/SMS_STYLE_GUIDE.md بندِ ۲۱-۱۳): متغیرِ
        // اختیاریِ خالی نباید جای خالی، ویرگولِ اضافه یا خطِ شکسته بگذارد — «:first_name عزیز،» کلاً حذف.
        if (str_contains($body, ':first_name') && trim((string) ($params['first_name'] ?? '')) === '') {
            $body = (string) preg_replace('/:first_name\s*عزیز،[ \t]*(\r?\n)?/u', '', $body);
            $body = str_replace(':first_name', '', $body);
            unset($params['first_name']);
        }

        if ($params === []) {
            return $body;
        }

        // بندِ ۱۵ِ دستورالعمل: اعدادِ نمایشی با ارقامِ فارسی؛ کدِ یک‌بارمصرف و شماره‌ی سفارش لاتین می‌مانند.
        foreach (self::PERSIAN_DIGIT_PARAMS as $name) {
            if (isset($params[$name]) && is_scalar($params[$name])) {
                $params[$name] = enToFa((string) $params[$name]);
            }
        }

        uksort($params, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));

        $search = [];
        $replace = [];

        foreach ($params as $name => $value) {
            $search[] = ':'.$name;
            $replace[] = (string) $value;
        }

        return str_replace($search, $replace, $body);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function fromLang(string $key, array $params): ?string
    {
        // متنِ خام + همان `interpolate` (نه جایگذاریِ مترجم) تا حذفِ «:first_name عزیز،»ِ خالی این‌جا هم برقرار باشد.
        return Lang::has("sms.{$key}") ? self::interpolate((string) __("sms.{$key}"), $params) : null;
    }

    /**
     * نامِ کوچکِ گیرنده برای «{first_name} عزیز،» — دستورالعملِ پیامکِ مدیریت، بندِ ۳. خالی یعنی خطاب حذف.
     */
    public static function firstNameFor(?string $mobile): string
    {
        if ($mobile === null || trim($mobile) === '') {
            return '';
        }

        $profile = User::query()->where(User::MOBILE, PhoneNumber::to09($mobile))->with('profile')->first()?->profile;
        $name = $profile instanceof UserProfile ? (string) $profile->{UserProfile::NAME} : '';

        // نامِ استعلام‌شده از ثبت‌احوال اغلب «ي/ك»ِ عربی دارد («سيدعليرضا»)؛ پیامک فارسی است.
        return trim(strtr($name, ['ي' => 'ی', 'ك' => 'ک']));
    }

    /**
     * فرستنده‌ای که نام را نداده: اگر متن «:first_name» دارد، از موبایلِ گیرنده پیدایش کن.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function withFirstName(string $body, array $params, ?string $mobile): array
    {
        if (str_contains($body, ':first_name') && ! array_key_exists('first_name', $params)) {
            $params['first_name'] = self::firstNameFor($mobile);
        }

        return $params;
    }
}
