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
        self::CREATED_AT,
        self::UPDATED_AT,
    ];

    /** @var array<string, string> */
    protected $casts = [
        self::TRANS_COMMISSION => 'boolean',
        self::TRANS_VAT => 'boolean',
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
