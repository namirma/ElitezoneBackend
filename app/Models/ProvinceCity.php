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
}
