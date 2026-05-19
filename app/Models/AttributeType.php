<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeType extends Model
{
    // ثابت‌ها
    public const ID = 'id';

    public const NAME = 'name';

    public const DISPLAY_NAME = 'display_name';

    public const INPUT_TYPE = 'input_type';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    // فیلدهای قابل‌پر شدن
    protected $fillable = [
        self::NAME,
        self::DISPLAY_NAME,
        self::INPUT_TYPE,
    ];

    /**
     * ارتباط یک‌به‌چند با Attribute
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class);
    }

    /**
     * ارتباط چندبه‌چند با ProductCategory
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductCategory::class,
            'category_attributes'
        )
            ->withPivot('is_required', 'is_filterable', 'sort_order')
            ->withTimestamps();
    }
}
