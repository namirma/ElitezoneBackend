<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $subtitle
 * @property string|null $description
 * @property string|null $image
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AttributeType> $attributeTypes
 * @property-read int|null $attribute_types_count
 * @property-read Collection<int, Product> $products
 * @property-read int|null $products_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ProductCategory extends Model
{
    const ID = 'id';

    const NAME = 'name';

    const SLUG = 'slug';

    /** خطِ دومِ هیروِ صفحه‌ی دسته‌بندی، وقتی بنرِ اختصاصی ثبت نشده. */
    const SUBTITLE = 'subtitle';

    const DESCRIPTION = 'description';

    const IMAGE = 'image';

    const IS_ACTIVE = 'is_active';

    protected $fillable = [
        self::NAME,
        self::SLUG,
        self::SUBTITLE,
        self::DESCRIPTION,
        self::IMAGE,
        self::IS_ACTIVE,
    ];

    protected $casts = [
        self::IS_ACTIVE => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function attributeTypes(): BelongsToMany
    {
        return $this->belongsToMany(AttributeType::class, 'category_attributes')
            ->withPivot('is_required', 'is_filterable', 'sort_order')
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }
}
