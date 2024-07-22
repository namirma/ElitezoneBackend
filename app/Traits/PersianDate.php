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


        if (@$this->attributes["created_at"])
            return Verta(@$this->attributes["created_at"])->formatDatetime();
    }

    /**
     * @return string|void
     */
    public function getUpdatedAtPAttribute()
    {
        if (@$this->attributes["updated_at"])
            return Verta(@$this->attributes["updated_at"])->formatDatetime();
    }

    /**
     * @return string|void
     */
    public function getUpdatedAtDiffAttribute()
    {
        if (@$this->attributes["updated_at"])
            return Carbon::make(@$this->attributes["updated_at"])->diffForHumans();
    }

    /**
     * @return string|void
     */
    public function getCreatedAtDiffAttribute()
    {
        if (@$this->attributes["created_at"])
            return Carbon::make(@$this->attributes["created_at"])->diffForHumans();
    }

    /**
     * @return string|void
     */
    public function getExpireAtDaysAttribute()
    {
        if (@$this->attributes["expire_at"]) {
            if (Carbon::parse(@$this->attributes["expire_at"])->isPast())
                return 0;

            return Carbon::make(@$this->attributes["expire_at"])->diffInDays();
        }
    }
}
