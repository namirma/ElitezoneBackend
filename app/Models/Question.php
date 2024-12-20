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

    protected $fillable = [
        self::NAME,
        self::PHONE,
        self::EMAIL,
        self::SUBJECT,
        self::MESSAGE
    ];
}
