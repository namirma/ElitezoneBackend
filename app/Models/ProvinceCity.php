<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProvinceCity extends Model
{
    // نام جدول طبق فایل مایگریشن شما
    protected $table = 'province_city';

    // چون در مایگریشن timestamps ندارید
    public $timestamps = false;

    protected $fillable = ['ProvinceID', 'Name'];

    /**
     * ستون واقعی `Name` (با N بزرگ) است ولی مصرف‌کننده‌ها `->name` می‌خوانند
     * (OrderResource، ShipmentResource، InvoiceDataBuilder) — و دسترسی به
     * اتریبیوت در Eloquent حساس به حرف بزرگ/کوچک است، پس تا امروز نام شهر در
     * جزئیات سفارش و **در آدرس خریدارِ روی فاکتورهای PDF** خالی می‌آمد.
     */
    public function getNameAttribute(): ?string
    {
        return $this->attributes['Name'] ?? null;
    }
}
