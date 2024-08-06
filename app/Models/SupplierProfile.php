<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static updateOrCreate(array $array, array $array1)
 * @method static where(array $array)
 */
class SupplierProfile extends Model
{
    use HasFactory;

    const
        ID = 'id',
        USER_ID = 'user_id',
        ACTIVITY_FIELD = 'activity_field',
        ACTIVITY_DESCRIPTION = 'activity_description',
        BRANDS = 'brands',
        BRANDS_NOT_RELATED = 'brands_not_related',
        ACTIVITY_HISTORY = 'activity_history',
        ACTIVITY_PROVINCES = 'activity_provinces',
        PRODUCTION_AMOUNT = 'production_amount',
        STANDARDS = 'standards',
        STANDARDS_NOT_RELATED = 'standards_not_related',
        WEBSITE = 'website',
        WEBSITE_NOT_RELATED = 'website_not_related',
        COMPANY = 'company',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        self::USER_ID,
        self::ACTIVITY_FIELD,
        self::ACTIVITY_DESCRIPTION,
        self::BRANDS,
        self::BRANDS_NOT_RELATED,
        self::ACTIVITY_HISTORY,
        self::ACTIVITY_PROVINCES,
        self::PRODUCTION_AMOUNT,
        self::STANDARDS,
        self::STANDARDS_NOT_RELATED,
        self::WEBSITE,
        self::WEBSITE_NOT_RELATED,
        self::COMPANY,
        self::CREATED_AT,
        self::UPDATED_AT
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
