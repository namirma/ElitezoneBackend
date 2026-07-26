<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const USER_ID = 'user_id';

    public const TITLE = 'title';

    public const PROVINCE_ID = 'province_id';

    public const CITY_ID = 'city_id';

    public const POSTAL_ADDRESS = 'postal_address';

    public const BUILDING_NUMBER = 'building_number';

    public const UNIT = 'unit';

    public const POSTAL_CODE = 'postal_code';

    public const LATITUDE = 'latitude';

    public const LONGITUDE = 'longitude';

    public const RECEIVER_NAME = 'receiver_name';

    public const RECEIVER_MOBILE = 'receiver_mobile';

    public const IS_DEFAULT = 'is_default';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::USER_ID,
        self::TITLE,
        self::PROVINCE_ID,
        self::CITY_ID,
        self::POSTAL_ADDRESS,
        self::BUILDING_NUMBER,
        self::UNIT,
        self::POSTAL_CODE,
        self::LATITUDE,
        self::LONGITUDE,
        self::RECEIVER_NAME,
        self::RECEIVER_MOBILE,
        self::IS_DEFAULT,
    ];

    protected $casts = [
        self::IS_DEFAULT => 'boolean',
        self::LATITUDE => 'decimal:8',
        self::LONGITUDE => 'decimal:8',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    public function province(): BelongsTo
    {
        // ⚠️ قبلاً به `ActivityProvince` وصل بود — آن جدولِ pivotِ «استان‌های
        // فعالیتِ سوپلایر» است (user_id + province_id) و لیست مرجع استان‌ها
        // نیست، پس `province_id` با `activity_provinces.id` تطبیق می‌شد و
        // همیشه null می‌داد: نام استان در جزئیات سفارشِ پنل ادمین، در
        // ShipmentResource و در آدرس خریدارِ فاکتورهای PDF خالی می‌ماند.
        // جدول درست `province` است — همان که `getProvince()` می‌خواند.
        return $this->belongsTo(Province::class, self::PROVINCE_ID);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(ProvinceCity::class, self::CITY_ID);
    }
}
