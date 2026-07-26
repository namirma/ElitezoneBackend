<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * جدول مرجع استان‌ها — همان جدولی که هلپر `getProvince()` و اندپوینت
 * `GET /api/province` می‌خوانند (۳۱ ردیف).
 *
 * ⚠️ با `ActivityProvince` اشتباه نشود: آن یک جدول pivot است
 * (`activity_provinces`: user_id + province_id) برای «استان‌های فعالیتِ»
 * پروفایل سوپلایر، نه لیست مرجع استان‌ها. `Address::province()` قبلاً به
 * همان pivot وصل بود، پس `province_id` را با `activity_provinces.id`
 * تطبیق می‌داد و همیشه null برمی‌گرداند.
 *
 * @property int $id
 * @property string $Name
 */
class Province extends Model
{
    protected $table = 'province';

    public $timestamps = false;

    protected $fillable = ['Name'];

    /**
     * ستون واقعی `Name` (با N بزرگ) است، ولی همه‌ی مصرف‌کننده‌ها `->name`
     * می‌خوانند (OrderResource، ShipmentResource، InvoiceDataBuilder). دسترسی
     * به اتریبیوت در Eloquent حساس به بزرگی/کوچکی حرف است، پس بدون این
     * accessor همه‌جا null می‌گرفتند — حتی وقتی رابطه درست resolve می‌شد.
     */
    public function getNameAttribute(): ?string
    {
        return $this->attributes['Name'] ?? null;
    }
}
