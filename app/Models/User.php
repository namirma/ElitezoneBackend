<?php

namespace App\Models;

use App\Models\Concerns\HasOrders;
use App\Models\Concerns\HasWallet;
use App\Services\Profile\EliteYARVerificationMethods;
use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laratrust\Traits\HasRolesAndPermissions;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens,
        HasFactory,
        HasOrders,
        HasRolesAndPermissions,
        HasWallet,
        Notifiable,
        PersianDate;

    // =========================================================================
    //  Constants (Database Columns)
    // =========================================================================
    public const ID = 'id';

    public const TYPE_ID = 'type_id';

    public const BADGE_ID = 'badge_id';

    /**
     * Invoice amount (Rials) the eliteYAR paid when purchasing their badge.
     * Basis for the lifetime commission cap (cap_multiplier × this amount).
     * NULL = no paid badge on record → the eliteYAR is uncapped.
     */
    public const BADGE_PURCHASE_AMOUNT = 'badge_purchase_amount';

    /**
     * عضوِ سازه ۹۰ است؟ — تشخیصِ بک‌اند از saze90.com، تنها نویسنده
     * `SyncUserBadgeAction` با `forceFill()`. عمداً در `$fillable` نیست.
     *
     * روی کاربر است نه پروفایلِ مهندس، چون آن ردیف فقط با ارسالِ مدرک ساخته
     * می‌شود و در لانچ (فقط سازه ۹۰) هیچ الیت‌یاری مدرک نمی‌فرستد.
     */
    public const IS_SAZE90_USER = 'is_saze90_user';

    public const MOBILE = 'mobile';

    public const IS_ACTIVE = 'is_active';

    /**
     * مجموع بدهی کاربر (ریال) — فاز ۹.
     *
     * **هرگز مستقیم ننویسید.** تنها نویسنده `DebtEngine` است، که ردیفِ دفتر و
     * مانده را با هم و زیرِ قفل می‌نویسد. نوشتنِ مستقیم یعنی مانده و
     * `debt_entries` از هم جدا بیفتند و هیچ‌کس نتواند بگوید کدام درست است.
     */
    public const DEBT_BALANCE = 'debt_balance';

    /** سقف اعتبار (ریال) — دستی توسط بک‌آفیس؛ پیش‌فرض صفر یعنی بدون اعتبار. */
    public const CREDIT_LIMIT = 'credit_limit';

    /**
     * سه‌حالته: true/false تصمیمِ صریحِ بک‌آفیس، `null` یعنی پیروی از تنظیمِ
     * سراسریِ `coupon_visibility`. فقط در حالتِ `selected` خوانده می‌شود.
     *
     * خواننده: App\Services\Sales\SalesSettings::couponVisibleFor()
     */
    public const COUPON_ENABLED = 'coupon_enabled';

    public const ADMIN_LEVEL = 'admin_level';

    /**
     * سطحِ اختیارِ ادمین — لایه‌ی دوم، بالای مجوزها.
     *
     * مجوز می‌گوید «به کدام بخش دسترسی دارد»؛ سطح می‌گوید «تا کجا حق دارد».
     * عددِ کوچک‌تر یعنی اختیارِ بیشتر، تا افزودنِ سطحِ محدودترِ جدید در آینده
     * فقط یک عددِ بزرگ‌تر باشد و ترتیبِ موجود را جابه‌جا نکند.
     */
    public const ADMIN_LEVEL_OWNER = 1;

    public const ADMIN_LEVEL_MANAGER = 2;

    public const ADMIN_LEVEL_AGENT = 3;

    public const ADMIN_LEVELS = [
        self::ADMIN_LEVEL_OWNER,
        self::ADMIN_LEVEL_MANAGER,
        self::ADMIN_LEVEL_AGENT,
    ];

    public const NATIONAL_CODE = 'national_code';

    public const BIRTH_DATE = 'birth_date';

    public const SECRET = 'secret';

    public const SECRET_EXPIRES_AT = 'secret_expires_at';

    public const PASSWORD = 'password';

    public const REMEMBER_TOKEN = 'remember_token';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    // Relation Names
    public const RELATION_PROFILE = 'profile';

    public const RELATION_ENGINEER = 'engineer';

    public const RELATION_CONTRACTOR = 'contractor';

    public const RELATION_SUPPLIER = 'supplier';

    public const RELATION_EMPLOYER = 'employer';

    public const RELATION_TYPE = 'type';

    public const RELATION_ROLES = 'roles';

    public const RELATION_BADGE = 'badge';

    public const RELATION_EDUIX_RECORDS = 'eduixRecords';

    public const RELATION_JOB_EXPERIENCES = 'jobExperiences';

    // =========================================================================
    //  Configurations
    // =========================================================================
    protected $fillable = [
        self::MOBILE,
        self::IS_ACTIVE,
        // ⚠️ `ADMIN_LEVEL` عمداً بیرون است (از ۲۰۲۶-۱۰-۰۳): سطحِ اختیارِ ادمین است و
        // یک `update($request->…)` بی‌دقت روی کاربر می‌توانست آن را بالا ببرد. فقط
        // `AdminManagerController` (پس از قاعده‌ی سقفِ اعطا) و `AdminSeeder` با
        // `forceFill()` می‌نویسندش.
        self::NATIONAL_CODE,
        self::BIRTH_DATE,
        self::SECRET,
        self::SECRET_EXPIRES_AT,
        self::TYPE_ID,
        self::PASSWORD,
        // ⚠️ `BADGE_ID` و `BADGE_PURCHASE_AMOUNT` عمداً بیرون‌اند، به همان دلیلِ
        // `debt_balance`/`credit_limit`: نشان، نرخِ کمیسیون را انتخاب می‌کند و
        // مبلغِ خریدِ نشان مبنای سقفِ مادام‌العمرِ الیت‌یار است. هر دو فقط از
        // مسیر ادمین/سینک با `forceFill()` نوشته می‌شوند — هرگز از درخواست.
    ];

    protected $hidden = [
        self::PASSWORD,
        self::REMEMBER_TOKEN,
        self::SECRET,
        self::SECRET_EXPIRES_AT,
    ];

    protected $casts = [
        self::PASSWORD => 'hashed',
        // مهلتِ OTP یک ستونِ زمانی است که همه‌جا با `isPast()` سنجیده می‌شود.
        // بدون cast، هر خواننده باید خودش `Carbon::parse` کند و اولین کسی که
        // یادش برود، روی رشته `isPast()` صدا می‌زند.
        //
        // ⚠️ `birth_date` عمداً cast نمی‌شود: مقدارش **شمسی** است
        // (`StoreUserRequest` با regex می‌پذیردش) و cast `date` آن را
        // میلادی تفسیر می‌کند.
        self::SECRET_EXPIRES_AT => 'datetime',
        self::IS_ACTIVE => 'boolean',
        self::IS_SAZE90_USER => 'boolean',
        self::COUPON_ENABLED => 'boolean',
        self::BADGE_PURCHASE_AMOUNT => 'decimal:0',
        self::DEBT_BALANCE => 'decimal:0',
        self::CREDIT_LIMIT => 'decimal:0',
    ];

    protected $appends = ['created_at_p', 'created_at_diff', 'updated_at_p', 'updated_at_diff'];

    // =========================================================================
    //  Relationships
    // =========================================================================

    public function getFullNameAttribute(): string
    {
        $profile = $this->profile;
        if (! $profile || empty($profile->name) && empty($profile->family)) {
            return 'کاربر ناشناس';
        }

        return trim("{$profile->name} {$profile->family}");
    }

    /** @return HasOne<UserProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function isEliteYAR(): bool
    {
        return $this->hasRole('engineer') || $this->hasRole('contractor');
    }

    /**
     * تنها محمولِ «الیت‌یارِ تأییدشده». همه‌جا — Resourceها، گیتِ سفارش، گیتِ
     * دعوت، فهرست‌های کارفرما — از همین بخوانید.
     *
     * تا ۱۴۰۵/۰۶/۳۰ پنج نسخه‌ی پراکنده داشت با دو فرمولِ متفاوت: دو Resource
     * سازه ۹۰ را می‌شمردند و سه گیت نه. نتیجه این بود که عضوِ سازه ۹۰ روی
     * پروفایلش «تأییدشده» می‌دید ولی دعوت و انتخابش روی سفارش ۴۲۲ می‌گرفت.
     *
     * فرمول: هر روش فقط وقتی می‌شمارد که در تنظیماتِ ادمین روشن باشد
     * (`EliteYARVerificationMethods`). تأییدِ دستیِ ادمین (`is_verified`ِ
     * پروفایل) پشتِ دو روشِ مدرکی است — با خاموش‌بودنِ هر دو، بی‌اثر است.
     */
    public function isVerifiedEliteYAR(): bool
    {
        $methods = EliteYARVerificationMethods::enabled();

        if ($methods[EliteYARVerificationMethods::SAZE90] && (bool) $this->{self::IS_SAZE90_USER}) {
            return true;
        }

        if ($methods[EliteYARVerificationMethods::EDUCATION] || $methods[EliteYARVerificationMethods::JOB]) {
            return (bool) ($this->engineer?->{EngineerProfile::IS_VERIFIED}
                || $this->contractor?->{ContractorProfile::IS_VERIFIED});
        }

        return false;
    }

    // `chaperone()`: وقتی پروفایل از راهِ کاربر لود می‌شود، `$profile->user` بدون
    // کوئریِ اضافه همان کاربر است — Resourceِ پروفایل برای `is_verified` لازمش دارد.
    /** @return HasOne<EngineerProfile, $this> */
    public function engineer(): HasOne
    {
        return $this->hasOne(EngineerProfile::class)->chaperone();
    }

    /** @return HasOne<ContractorProfile, $this> */
    public function contractor(): HasOne
    {
        return $this->hasOne(ContractorProfile::class)->chaperone();
    }

    public function supplier(): HasOne
    {
        return $this->hasOne(SupplierProfile::class);
    }

    // return type لازم است تا larastan این را relation بشناسد؛ بدونش هر
    // `$user->wallet` یک خطای «خاصیت تعریف‌نشده» می‌شد و ۱۰ ورودی در baseline داشت.
    // پارامترهای جنریک هم لازم‌اند: larastan روی لاراول ۱۳ یک `HasOne`ِ برهنه را
    // `HasOne<Model, Model>` حل می‌کند و باز هم به `Model` می‌رسد.
    /** @return HasOne<Wallet, $this> */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function employer(): HasOne
    {
        return $this->hasOne(EmployerProfile::class);
    }

    // ── ورودِ دومرحله‌ای (TOTP) ──────────────────────────────────────────────
    /** @return HasOne<UserTwoFactor, $this> */
    public function twoFactor(): HasOne
    {
        return $this->hasOne(UserTwoFactor::class);
    }

    /**
     * ردیفِ تأییدنشده (setup بدونِ confirm) در ورود بی‌اثر است.
     *
     * عمداً کوئری است نه رابطه‌ی کش‌شده: روی یک نمونه‌ی زنده‌ی User (تست، Octane)
     * `$this->twoFactor` پس از اولین خواندن ثابت می‌ماند و فعال‌شدنِ بعدی را نمی‌بیند.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->twoFactor()->whereNotNull(UserTwoFactor::ENABLED_AT)->exists();
    }
    // ── پایانِ ورودِ دومرحله‌ای ──────────────────────────────────────────────

    public function type(): BelongsTo
    {
        return $this->belongsTo(UserType::class, self::TYPE_ID);
    }

    /** @return BelongsTo<Badge, $this> */
    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class, self::BADGE_ID);
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'badge_user')
            ->withTimestamps();
    }

    // --- روابط عمومی ---

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, Address::USER_ID);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(UserBankAccount::class, UserBankAccount::USER_ID);
    }

    public function searches(): HasMany
    {
        return $this->hasMany(Search::class);
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, Wishlist::USER_ID);
    }

    public function cart(): HasOne
    {
        // ترتیب صریح = همان ردیفی که Cart::queryForUser/resolveForUser برمی‌گردانند.
        // بدون آن، انتخابِ ردیف به ترتیبِ دلخواهِ دیتابیس واگذار می‌شد.
        return $this->hasOne(Cart::class)->orderBy(Cart::ID);
    }

    public function eduixRecords(): HasMany
    {
        return $this->hasMany(EduixRecord::class, EduixRecord::USER_ID);
    }

    public function jobExperiences(): HasMany
    {
        return $this->hasMany(JobExperience::class, JobExperience::USER_ID);
    }

    public function stockSubscriptions(): HasMany
    {
        return $this->hasMany(StockSubscription::class, StockSubscription::USER_ID);
    }

    /** Admin back-office notes written about this user. */
    public function notes(): HasMany
    {
        return $this->hasMany(UserNote::class, UserNote::USER_ID);
    }

    public function technicalEliteYARs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employer_elite_yar', EmployerEliteYAR::EMPLOYER_ID, EmployerEliteYAR::ELITE_YAR_ID)
            ->using(EmployerEliteYAR::class)
            ->withPivot([EmployerEliteYAR::STATUS, EmployerEliteYAR::REJECT_REASON])
            ->withTimestamps();
    }

    public function associatedEmployers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employer_elite_yar', EmployerEliteYAR::ELITE_YAR_ID, EmployerEliteYAR::EMPLOYER_ID)
            ->using(EmployerEliteYAR::class)
            ->withPivot([EmployerEliteYAR::STATUS, EmployerEliteYAR::REJECT_REASON])
            ->withTimestamps();
    }

    /** مشتریانی که این الیت یار از طریق جریان «خرید برای دیگری» احراز هویت کرده */
    /** @return BelongsToMany<User, $this> */
    public function registeredClients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'elite_yar_registered_clients', 'elite_yar_user_id', 'client_user_id')
            ->withTimestamps();
    }

    public function agreements(): BelongsToMany
    {
        return $this->belongsToMany(Agreement::class, 'user_agreements')
            ->withPivot([
                'id', 'accepted_at', 'version', 'content_sha256', 'ip_address',
                'user_agent', 'checked_items', 'acceptance_method', 'snapshot',
            ])
            ->withTimestamps();
    }

    public function hasAcceptedLatestAgreement(string $slug): bool
    {
        // منبع واحد «آخرین نسخه فعال» — tie-break با ID برای نسخه‌هایی که در یک
        // ثانیه ساخته شده‌اند (latest(created_at) به‌تنهایی غیرقطعی بود).
        $latestAgreement = Agreement::latestActiveForSlug($slug);

        if (! $latestAgreement) {
            return true;
        }

        // ستون باید qualified باشد — کوئری pivot جوین user_agreements را دارد و
        // «id» بدون پیشوند بین دو جدول مبهم است (SQLSTATE 1052).
        return $this->agreements()
            ->where('agreements.'.Agreement::ID, $latestAgreement->{Agreement::ID})
            ->exists();
    }

    /**
     * آیا این ادمین دستِ‌کم در سطحِ خواسته‌شده است؟ (عددِ کوچک‌تر = اختیارِ بیشتر)
     *
     * fail-closed: ادمینی که ستونش NULL است هیچ سطحی ندارد و رد می‌شود. این حالت
     * نباید پیش بیاید (مایگریشن ادمین‌های موجود را پر کرد؛ `AdminManagerController::store`
     * و `AdminSeeder` از ۲۰۲۶-۱۰-۰۳ سطح می‌نویسند — پیش از آن نمی‌نوشتند و همین
     * حالت واقعاً رخ می‌داد)، ولی اگر ردیفی از راهِ دیگری ساخته شد، «سطحِ نامعلوم» باید یعنی «اجازه ندارد»
     * نه «اجازه دارد» — همان اشتباهی که یک‌بار در مجوزها رخ داد و ادمینِ
     * محدودشده را به دسترسیِ کامل رساند.
     */
    public function hasAdminLevel(int $minimumLevel): bool
    {
        $level = $this->{self::ADMIN_LEVEL};

        return $level !== null && (int) $level <= $minimumLevel;
    }

    public function isOwnerAdmin(): bool
    {
        return $this->hasAdminLevel(self::ADMIN_LEVEL_OWNER);
    }

    /**
     * سطح‌هایی که این ادمین حقِ دادنشان را دارد: سطحِ خودش و پایین‌تر.
     *
     * قاعده‌ی سقفِ اعطا — هیچ‌کس اختیاری بیش از خودش به دیگری نمی‌دهد. امروز فقط مدیر
     * ارشد به گروهِ مدیران می‌رسد، پس عملاً هر سه سطح است؛ روزی که گروه برای «مدیر»
     * باز شود، همین جلوی ارتقای خودخواسته را می‌گیرد. بی‌سطح هیچ سطحی نمی‌دهد.
     *
     * @return list<int>
     */
    public function grantableAdminLevels(): array
    {
        $own = $this->{self::ADMIN_LEVEL};

        if ($own === null) {
            return [];
        }

        return array_values(array_filter(self::ADMIN_LEVELS, fn (int $level) => $level >= (int) $own));
    }
}
