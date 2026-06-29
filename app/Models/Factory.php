<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Factory extends Model
{
    const ID = 'id';

    const NAME = 'name';

    const SLUG = 'slug';

    const DESCRIPTION = 'description';

    const LOGO = 'logo';

    const API_KEY = 'api_key';

    const API_URL = 'api_url';

    const IS_ACTIVE = 'is_active';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    const SUPPLIER_USER_ID = 'supplier_user_id';

    protected $fillable = [
        self::NAME,
        self::SLUG,
        self::DESCRIPTION,
        self::LOGO,
        self::API_KEY,
        self::API_URL,
        self::IS_ACTIVE,
        self::SUPPLIER_USER_ID,
    ];

    public function locations(): HasMany
    {
        return $this->hasMany(FactoryLocation::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, self::SUPPLIER_USER_ID);
    }
}
