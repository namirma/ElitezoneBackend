<?php

namespace App\Models;

use App\Models\Concerns\HasOrders;
use App\Models\Concerns\HasWallet;
use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laratrust\Traits\HasRolesAndPermissions;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable
{
    use HasApiTokens,
        HasFactory,
        Notifiable,
        PersianDate,
        HasRolesAndPermissions,
        HasWallet,
        HasOrders;

    // =========================================================================
    //  Constants (Database Columns)
    // =========================================================================
    public const
        ID = 'id',
        TYPE_ID = 'type_id',
        BADGE_ID = 'badge_id',
        MOBILE = 'mobile',
        NATIONAL_CODE = 'national_code',
        BIRTH_DATE = 'birth_date',
        SECRET = 'secret',
        SECRET_EXPIRES_AT = 'secret_expires_at',
        PASSWORD = 'password',
        REMEMBER_TOKEN = 'remember_token',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    // Relation Names
    public const
        RELATION_PROFILE = 'profile',
        RELATION_ENGINEER = 'engineer',
        RELATION_CONTRACTOR = 'contractor',
        RELATION_SUPPLIER = 'supplier',
        RELATION_EMPLOYER = 'employer',
        RELATION_TYPE = 'type',
        RELATION_ROLES = 'roles',
        RELATION_BADGE = 'badge',
        RELATION_EDUIX_RECORDS = 'eduixRecords',
        RELATION_JOB_EXPERIENCES = 'jobExperiences';

    // =========================================================================
    //  Configurations
    // =========================================================================
    protected $fillable = [
        self::MOBILE,
        self::NATIONAL_CODE,
        self::BIRTH_DATE,
        self::SECRET,
        self::SECRET_EXPIRES_AT,
        self::TYPE_ID,
        self::PASSWORD,
        self::BADGE_ID,
    ];

    protected $hidden = [
        self::PASSWORD,
        self::REMEMBER_TOKEN,
        self::SECRET,
        self::SECRET_EXPIRES_AT,
    ];

    protected $casts = [
        self::PASSWORD => 'hashed',
    ];

    protected $appends = ['created_at_p', 'created_at_diff', 'updated_at_p', 'updated_at_diff'];

    // =========================================================================
    //  Relationships
    // =========================================================================

    public function getFullNameAttribute(): string
    {
        $profile = $this->profile;
        if (!$profile || empty($profile->name) && empty($profile->family)) {
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
