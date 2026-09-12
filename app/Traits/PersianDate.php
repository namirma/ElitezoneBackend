<?php

namespace App\Traits;

use Carbon\Carbon;

/**
 * this trait is used to append persian date to all models
 */
trait PersianDate
{
    /**
     * @return string|void
     */
    public function getCreatedAtPAttribute()
    {
        // test server
        if (@$this->attributes['created_at']) {
            return Verta(@$this->attributes['created_at'])->formatDatetime();
        }
    }

    /**
     * @return string|void
     */
    public function getUpdatedAtPAttribute()
    {
        if (@$this->attributes['updated_at']) {
            return Verta(@$this->attributes['updated_at'])->formatDatetime();
        }
    }

    /**
     * @return string|void
     */
    public function getUpdatedAtDiffAttribute()
    {
        if (@$this->attributes['updated_at']) {
            return Carbon::make(@$this->attributes['updated_at'])->diffForHumans();
        }
    }

    /**
     * @return string|void
     */
    public function getCreatedAtDiffAttribute()
    {
        if (@$this->attributes['created_at']) {
            return Carbon::make(@$this->attributes['created_at'])->diffForHumans();
        }
    }

    /**
     * @return int|void
     */
    public function getExpireAtDaysAttribute()
    {
        if (@$this->attributes['expire_at']) {
            if (Carbon::parse(@$this->attributes['expire_at'])->isPast()) {
                return 0;
            }

            // ترتیبِ عملوندها عمدی است: Carbon 3 (لاراول ۱۳) عددِ **علامت‌دار**
            // برمی‌گرداند و Carbon 2 قدرمطلق. با `$expireAt->diffInDays()` — که
            // یعنی «از expire تا now» — روزهای باقی‌مانده منفی درمی‌آمد. بالا
            // تضمین شده expire_at در گذشته نیست، پس now→expire مثبت است.
            return (int) now()->diffInDays(Carbon::parse(@$this->attributes['expire_at']));
        }
    }
}
