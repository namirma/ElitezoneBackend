<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $version
 * @property string|null $content
 * @property bool $is_active
 * @property bool $is_mandatory
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereIsMandatory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereVersion($value)
 * @mixin \Eloquent
 */
class Agreement extends Model
{
    use HasFactory;

    // =========================================================================
    //  Constants (Database Columns)
    // =========================================================================
    public const ID = 'id';

    public const SLUG = 'slug';

    public const VERSION = 'version';

    public const CONTENT = 'content';

    public const IS_ACTIVE = 'is_active';

    public const IS_MANDATORY = 'is_mandatory';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    // =========================================================================
    //  Configurations
    // =========================================================================
    protected $fillable = [
        self::SLUG,
        self::VERSION,
        self::CONTENT,
        self::IS_ACTIVE,
        self::IS_MANDATORY,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
        self::IS_MANDATORY => 'boolean',
    ];

    // =========================================================================
    //  Scopes
    // =========================================================================
    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    // =========================================================================
    //  Relationships
    // =========================================================================
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_agreements')
            ->withPivot(['accepted_at', 'ip_address', 'user_agent'])
            ->withTimestamps();
    }
}
