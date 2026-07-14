<?php

namespace App\Models;

use GuzzleHttp\Promise\Create;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static create(mixed $validated)
 * @property int $id
 * @property string $name
 * @property string $mobile
 * @property string $email
 * @property string $subject
 * @property string $message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereMobile($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereUpdatedAt($value)
 * @mixin \Eloquent
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
