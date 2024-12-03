<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @method static create(array $array)
 * @method static where(string $string, string $string1)
 */
class ActivityCategory extends Model
{
    use HasFactory;

    protected $guarded  = ['name', 'parent_id', 'slug'];
    //protected $hidden = ['created_at', 'updated_at'];

    public function subcategories(): HasMany
    {
        return $this->hasMany(ActivityCategory::class, 'parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ActivityCategory::class, 'parent_id');
    }
    public function children(): HasMany
    {
        return $this->hasMany(ActivityCategory::class, 'parent_id');
    }
    /*protected array $dates = ['created_at', 'updated_at'];
    public function getCreatedAtAttribute($value): string
    {
        return Carbon::parse($value)->format('Y-m-d\h:i:s.v');
    }

    public function getUpdatedAtAttribute($value): string
    {
        return Carbon::parse($value)->format('Y-m-d\h:i:s.v');
    }*/

}
