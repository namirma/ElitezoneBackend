<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    use HasFactory;

    const TABLE = 'faqs';

    const ID = 'id';

    const QUESTION = 'question';

    const ANSWER = 'answer';

    const CATEGORY = 'category';

    const SORT_ORDER = 'sort_order';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $table = self::TABLE;

    protected $fillable = [
        self::QUESTION,
        self::ANSWER,
        self::CATEGORY,
        self::SORT_ORDER,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::SORT_ORDER => 'integer',
        self::IS_ACTIVE => 'boolean',
    ];
}
