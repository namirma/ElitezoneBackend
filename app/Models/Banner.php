<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;
    use PersianDate;

    const TABLE = 'banners';

    const ID = 'id';

    const TITLE = 'title';

    const IMAGE = 'image';

    const LINK = 'link';

    const POSITION = 'position';

    const SORT_ORDER = 'sort_order';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $table = self::TABLE;

    protected $fillable = [
        self::TITLE,
        self::IMAGE,
        self::LINK,
        self::POSITION,
        self::SORT_ORDER,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::SORT_ORDER => 'integer',
        self::IS_ACTIVE => 'boolean',
    ];
}
