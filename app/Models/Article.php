<?php

namespace App\Models;

use App\Traits\PersianDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    use HasFactory;
    use PersianDate;

    const TABLE = 'articles';

    const ID = 'id';

    const TITLE = 'title';

    const SLUG = 'slug';

    const EXCERPT = 'excerpt';

    const BODY = 'body';

    const COVER = 'cover';

    const AUTHOR_ID = 'author_id';

    const IS_ACTIVE = 'is_active';

    const PUBLISHED_AT = 'published_at';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    protected $table = self::TABLE;

    protected $fillable = [
        self::TITLE,
        self::SLUG,
        self::EXCERPT,
        self::BODY,
        self::COVER,
        self::AUTHOR_ID,
        self::IS_ACTIVE,
        self::PUBLISHED_AT,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
        self::PUBLISHED_AT => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, self::AUTHOR_ID);
    }
}
