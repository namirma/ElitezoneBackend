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

/**
 * @property int $id
 * @property int|null $type_id
 * @property int|null $badge_id
 * @property numeric|null $badge_purchase_amount Invoice amount (Rials) the consultant paid for their badge; basis for the lifetime commission cap. NULL = uncapped.
 * @property string $mobile
 * @property bool $is_active
 * @property string|null $national_code
 * @property string|null $birth_date
 * @property string|null $secret
 * @property string|null $secret_expires_at
 * @property string|null $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Address> $addresses
 * @property-read int|null $addresses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Agreement> $agreements
 * @property-read int|null $agreements_count
 * @property-read \App\Models\EmployerConsultant|null $pivot
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $associatedEmployers
 * @property-read int|null $associated_employers_count
 * @property-read \App\Models\Badge|null $badge
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Badge> $badges
 * @property-read int|null $badges_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserBankAccount> $bankAccounts
 * @property-read int|null $bank_accounts_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $beneficiaryOrders
 * @property-read int|null $beneficiary_orders_count
 * @property-read \App\Models\Cart|null $cart
 * @property-read \App\Models\ContractorProfile|null $contractor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EduixRecord> $eduixRecords
 * @property-read int|null $eduix_records_count
 * @property-read \App\Models\EmployerProfile|null $employer
 * @property-read \App\Models\EngineerProfile|null $engineer
 * @property-read float $balance
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string $full_name
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\JobExperience> $jobExperiences
 * @property-read int|null $job_experiences_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserNote> $notes
 * @property-read int|null $notes_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $placedOrders
 * @property-read int|null $placed_orders_count
 * @property-read \App\Models\UserProfile|null $profile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $registeredClients
 * @property-read int|null $registered_clients_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Search> $searches
 * @property-read int|null $searches_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StockSubscription> $stockSubscriptions
 * @property-read int|null $stock_subscriptions_count
 * @property-read \App\Models\SupplierProfile|null $supplier
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $technicalConsultants
 * @property-read int|null $technical_consultants_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property-read \App\Models\UserType|null $type
 * @property-read \App\Models\Wallet|null $wallet
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Wishlist> $wishlists
 * @property-read int|null $wishlists_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User orWhereHasPermission(\BackedEnum|array|string $permission = '', ?mixed $team = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User orWhereHasRole(\BackedEnum|array|string $role = '', ?mixed $team = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereBadgeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereBadgePurchaseAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereBirthDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDoesntHavePermissions()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDoesntHaveRoles()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereHasPermission(\BackedEnum|array|string $permission = '', ?mixed $team = null, string $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereHasRole(\BackedEnum|array|string $role = '', ?mixed $team = null, string $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMobile($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSecretExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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

    public function isConsultant(): bool
    {
        return $this->hasRole('engineer') || $this->hasRole('contractor');
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

    /** مشتریانی که این مشاور فنی از طریق جریان «خرید برای دیگری» احراز هویت کرده */
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
