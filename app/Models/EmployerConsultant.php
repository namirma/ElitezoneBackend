<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * این مدل برای مدیریت جدول واسط بین کارفرما و مشاور فنی استفاده می‌شود
 * و ثابت‌های مربوط به آن را نگهداری می‌کند.
 */
class EmployerConsultant extends Pivot
{
    /**
     * نام جدول در دیتابیس
     */
    protected $table = 'employer_consultant';

    public const
     EMPLOYER_ID = 'employer_id',
     CONSULTANT_ID = 'consultant_id',
     STATUS = "status",
     STATUS_PENDING = 'pending',
     STATUS_APPROVED = 'approved',
     STATUS_REJECTED = 'rejected';
}
