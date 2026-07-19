<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasFactory;

    const TABLE = 'pages';

    const ID = 'id';

    const TITLE = 'title';

    const SLUG = 'slug';

    const BODY = 'body';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $table = self::TABLE;

    protected $fillable = [
        self::TITLE,
        self::SLUG,
        self::BODY,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
    ];
}
