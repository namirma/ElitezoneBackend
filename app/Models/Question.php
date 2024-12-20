<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static create(mixed $validated)
 */
class Question extends Model
{
    use HasFactory;

    const
        NAME = 'name',
        PHONE = 'phone',
        EMAIL = 'email',
        SUBJECT = 'subject',
        MESSAGE = 'message';

    public static array $qTypes = [
        ' ,پیشنهاد,انتقاد و شکایات',
        'سایر موضوعات',
        'گزارش خطا در نحوه عملکرد وب سایت',
        'مدیریت',
        'حسابداری و امور مالی'
    ];

    protected $fillable = [
        self::NAME,
        self::PHONE,
        self::EMAIL,
        self::SUBJECT,
        self::MESSAGE
    ];
}
