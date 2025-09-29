<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Search;
use App\Models\Wishlist;
use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laratrust\Contracts\LaratrustUser;
use Laravel\Sanctum\HasApiTokens;
use Laratrust\Traits\HasRolesAndPermissions;

/**
 * @method static create(array $array)
 * @method static where(array $array)
 * @method static firstOrCreate(array $array)
 * @method static find(int|string|null $id)
 * @method static updateOrCreate(array $array, array $array1)
 */

class User extends Authenticatable implements LaratrustUser
{
    use HasApiTokens, HasFactory, Notifiable, PersianDate, HasRolesAndPermissions;

    const
        ID = 'id',
        TYPE_ID = 'type_id',
        MOBILE = 'mobile',
        NATIONAL_CODE = 'national_code',
        BIRTH_DATE = 'birth_date',
        SECRET = 'secret',
        SECRET_EXPIRES_AT = 'secret_expires_at',
        PASSWORD = 'password',
        REMEMBER_TOKEN = 'remember_token',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

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
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        self::PASSWORD,
        self::REMEMBER_TOKEN,
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
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

    public function type(): HasOne
    {
        return $this->hasOne(UserType::class);
    }
    public function searches(): HasMany
    {
        return $this->hasMany(Search::class);
    }
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, Wishlist::USER_ID);
    }
}
