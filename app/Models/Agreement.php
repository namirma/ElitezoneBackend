<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Agreement extends Model
{
    use HasFactory;

    // =========================================================================
    //  Constants (Database Columns)
    // =========================================================================
    public const
        ID = 'id',
        SLUG = 'slug',
        VERSION = 'version',
        CONTENT = 'content',
        IS_ACTIVE = 'is_active',
        IS_MANDATORY = 'is_mandatory',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

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
