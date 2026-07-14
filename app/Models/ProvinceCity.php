<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $ProvinceID
 * @property string $Name
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity whereProvinceID($value)
 * @mixin \Eloquent
 */
class ProvinceCity extends Model
{
    // نام جدول طبق فایل مایگریشن شما
    protected $table = 'province_city';

    // چون در مایگریشن timestamps ندارید
    public $timestamps = false;

    protected $fillable = ['ProvinceID', 'Name'];
}
