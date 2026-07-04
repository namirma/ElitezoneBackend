<?php

namespace App\Models;

use App\Models\Concerns\HasOrders;
use App\Models\Concerns\HasWallet;
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
     * Invoice amount (Rials) the consultant paid when purchasing their badge.
     * Basis for the lifetime commission cap (cap_multiplier × this amount).
     * NULL = no paid badge on record → the consultant is uncapped.
     */
    public const BADGE_PURCHASE_AMOUNT = 'badge_purchase_amount';

    public const MOBILE = 'mobile';

    public const IS_ACTIVE = 'is_active';

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
        self::NATIONAL_CODE,
        self::BIRTH_DATE,
        self::SECRET,
        self::SECRET_EXPIRES_AT,
        self::TYPE_ID,
        self::PASSWORD,
        self::BADGE_ID,
        self::BADGE_PURCHASE_AMOUNT,
    ];

    protected $hidden = [
        self::PASSWORD,
        self::REMEMBER_TOKEN,
        self::SECRET,
        self::SECRET_EXPIRES_AT,
    ];

    protected $casts = [
        self::PASSWORD => 'hashed',
        self::IS_ACTIVE => 'boolean',
        self::BADGE_PURCHASE_AMOUNT => 'decimal:2',
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

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function engineer(): HasOne
    {
        return $this->hasOne(EngineerProfile::class);
    }

    public function contractor(): HasOne
    {
        return $this->hasOne(ContractorProfile::class);
    }

    public function supplier(): HasOne
    {
        return $this->hasOne(SupplierProfile::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function employer(): HasOne
    {
        return $this->hasOne(EmployerProfile::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(UserType::class, self::TYPE_ID);
    }

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
        return $this->hasOne(Cart::class);
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

    public function technicalConsultants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employer_consultant', EmployerConsultant::EMPLOYER_ID, EmployerConsultant::CONSULTANT_ID)
            ->using(EmployerConsultant::class)
            ->withPivot([EmployerConsultant::STATUS, EmployerConsultant::REJECT_REASON])
            ->withTimestamps();
    }

    public function associatedEmployers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employer_consultant', EmployerConsultant::CONSULTANT_ID, EmployerConsultant::EMPLOYER_ID)
            ->using(EmployerConsultant::class)
            ->withPivot([EmployerConsultant::STATUS, EmployerConsultant::REJECT_REASON])
            ->withTimestamps();
    }

    /** مشتریانی که این الیت یار از طریق جریان «خرید برای دیگری» احراز هویت کرده */
    public function registeredClients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'consultant_registered_clients', 'consultant_user_id', 'client_user_id')
            ->withTimestamps();
    }

    public function agreements(): BelongsToMany
    {
        return $this->belongsToMany(Agreement::class, 'user_agreements')
            ->withPivot(['accepted_at', 'ip_address', 'user_agent'])
            ->withTimestamps();
    }

    public function hasAcceptedLatestAgreement(string $slug): bool
    {

        $latestAgreement = Agreement::query()
            ->where(Agreement::SLUG, $slug)
            ->active()
            ->latest(Agreement::CREATED_AT)
            ->first();

        if (! $latestAgreement) {
            return true;
        }

        return $this->agreements()
            ->where(Agreement::ID, $latestAgreement->{Agreement::ID})
            ->exists();
    }
}
