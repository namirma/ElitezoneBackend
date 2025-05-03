<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FactoryLocation extends Model
{
    use HasFactory;

    public const
        ID         = 'id',
        FACTORY_ID = 'factory_id',
        NAME       = 'name',
        TYPE       = 'type',
        CITY       = 'city',
        ADDRESS    = 'address',
        IS_ACTIVE  = 'is_active',
        CREATED_AT = 'created_at',
        UPDATED_AT = 'updated_at';

    protected $fillable = [
        self::FACTORY_ID,
        self::NAME,
        self::TYPE,
        self::CITY,
        self::ADDRESS,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
    ];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }
}
