<?php

namespace App\Models;

use GuzzleHttp\Promise\Create;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static create(mixed $validated)
 */
class Question extends Model
{
    use HasFactory;

    const NAME = 'name';

    const MOBILE = 'mobile';

    const EMAIL = 'email';

    const SUBJECT = 'subject';

    const MESSAGE = 'message';

    public static array $qTypes = [
        ' ,پیشنهاد,انتقاد و شکایات',
        'سایر موضوعات',
        'گزارش خطا در نحوه عملکرد وب سایت',
        'مدیریت',
        'حسابداری و امور مالی',
    ];

    protected $fillable = [
        self::NAME,
        self::MOBILE,
        self::EMAIL,
        self::SUBJECT,
        self::MESSAGE,
    ];
}
