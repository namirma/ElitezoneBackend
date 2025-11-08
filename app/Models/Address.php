<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    use HasFactory;

    public const
        ID = 'id',
        USER_ID = 'user_id',
        TITLE = 'title',
        PROVINCE_ID = 'province_id',
        CITY_ID = 'city_id',
        POSTAL_ADDRESS = 'postal_address',
        BUILDING_NUMBER = 'building_number',
        UNIT = 'unit',
        POSTAL_CODE = 'postal_code',
        LATITUDE = 'latitude',
        LONGITUDE = 'longitude',
        RECEIVER_NAME = 'receiver_name',
        RECEIVER_MOBILE = 'receiver_mobile',
        IS_DEFAULT = 'is_default',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

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
        return $this->belongsTo(\App\Models\ActivityProvince::class, self::PROVINCE_ID);
    }

    // public function city(): BelongsTo
    // {
    //     return $this->belongsTo(\App\Models\ProvinceCity::class, self::CITY_ID);
    // }
}
