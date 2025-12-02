<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Search;
use App\Models\Wishlist;
use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laratrust\Contracts\LaratrustUser;
use Laravel\Sanctum\HasApiTokens;
use Laratrust\Traits\HasRolesAndPermissions;

/**
 * @property int $id
 * @property int|null $type_id
 * @property int|null $badge_id
 * @property string $mobile
 * @property string|null $national_code
 * @property string|null $birth_date
 * @property-read UserProfile|null $profile
 * @property-read EngineerProfile|null $engineer
 * @property-read ContractorProfile|null $contractor
 * @property-read SupplierProfile|null $supplier
 * @property-read EmployerProfile|null $employer
 * @property-read UserType|null $type
 * @property-read Badge|null $badge
 * @property-read \Illuminate\Database\Eloquent\Collection|EduixRecord[] $eduixRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|JobExperience[] $jobExperiences
 * @property-read \Illuminate\Database\Eloquent\Collection|Role[] $roles
 * @property-read Wallet|null $wallet
 */
class User extends Authenticatable implements LaratrustUser
{
    use HasApiTokens, HasFactory, Notifiable, PersianDate, HasRolesAndPermissions;

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


    protected $fillable = [
        self::MOBILE,
        self::NATIONAL_CODE,
        self::BIRTH_DATE,
        self::SECRET,
        self::SECRET_EXPIRES_AT,
        self::TYPE_ID,
        self::PASSWORD,
        self::CREATED_AT,
        self::UPDATED_AT,
        self::BADGE_ID,
    ];

    protected $hidden = [
        self::PASSWORD,
        self::REMEMBER_TOKEN,
    ];

    protected $casts = [
        self::PASSWORD => 'hashed',
    ];

    public function getCreatedAtAttribute()
    {
        $this->appends[] = "created_at_p";
        $this->appends[] = "created_at_diff";
        return $this->attributes["created_at"];
    }

    public function getUpdatedAtAttribute()
    {
        $this->appends[] = "updated_at_p";
        $this->appends[] = "updated_at_diff";
        return $this->attributes["updated_at"];
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

    public function employer(): HasOne
    {
        return $this->hasOne(EmployerProfile::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(UserType::class, self::TYPE_ID);
    }
    public function searches(): HasMany
    {
        return $this->hasMany(Search::class);
    }
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, Wishlist::USER_ID);
    }


    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class, self::BADGE_ID);
    }

    public function stockSubscriptions(): HasMany
    {
        return $this->hasMany(StockSubscription::class, StockSubscription::USER_ID);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, Address::USER_ID);
    }

    public function eduixRecords(): HasMany
    {
        return $this->hasMany(EduixRecord::class, EduixRecord::USER_ID);
    }

    public function jobExperiences(): HasMany
    {
        return $this->hasMany(JobExperience::class, JobExperience::USER_ID);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(UserBankAccount::class, UserBankAccount::USER_ID);
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
}
