<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @method static updateOrCreate(array $array, array $array1)
 * @method static where(array $array)
 * @method static withoutChildren()
 * @property int $id
 * @property int $user_id
 * @property string|null $activity_description
 * @property string|null $brands
 * @property int $brands_not_related
 * @property int|null $activity_history
 * @property string|null $activity_provinces
 * @property string|null $production_amount میزان تولید
 * @property string|null $standards استاندارد‌های کسب شده
 * @property int $standards_not_related
 * @property string|null $website
 * @property int $website_not_related
 * @property string|null $company
 * @property bool $trans_commission +%TC: آیا کمیسیون روی هزینه حمل اعمال می‌شود؟
 * @property bool $trans_vat +VAT: آیا مالیات بر ارزش افزوده روی هزینه حمل اعمال می‌شود؟
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SupplierActivityCategory> $activities
 * @property-read int|null $activities_count
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereActivityDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereActivityHistory($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereActivityProvinces($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereBrands($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereBrandsNotRelated($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereCompany($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereProductionAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereStandards($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereStandardsNotRelated($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereTransCommission($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereTransVat($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereWebsite($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereWebsiteNotRelated($value)
 * @mixin \Eloquent
 */
class SupplierProfile extends Model
{
    use HasFactory, PersianDate;

    const ID = 'id';

    const USER_ID = 'user_id';

    const ACTIVITY_DESCRIPTION = 'activity_description';

    const BRANDS = 'brands';

    const BRANDS_NOT_RELATED = 'brands_not_related';

    const ACTIVITY_HISTORY = 'activity_history';

    const ACTIVITY_PROVINCES = 'activity_provinces';

    const PRODUCTION_AMOUNT = 'production_amount';

    const STANDARDS = 'standards';

    const STANDARDS_NOT_RELATED = 'standards_not_related';

    const WEBSITE = 'website';

    const WEBSITE_NOT_RELATED = 'website_not_related';

    const COMPANY = 'company';

    /** +%TC: کمیسیون روی هزینه حمل اعمال می‌شود؟ (پیش‌فرض: بله) */
    const TRANS_COMMISSION = 'trans_commission';

    /** +VAT: مالیات بر ارزش افزوده روی هزینه حمل اعمال می‌شود؟ (پیش‌فرض: بله) */
    const TRANS_VAT = 'trans_vat';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        self::USER_ID,
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
        self::TRANS_COMMISSION,
        self::TRANS_VAT,
    ];

    /** @var array<string, string> */
    protected $casts = [
        self::TRANS_COMMISSION => 'boolean',
        self::TRANS_VAT        => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(SupplierActivityCategory::class, 'supplier_activities', 'supplier_id', 'activity_category_id');
    }
}
