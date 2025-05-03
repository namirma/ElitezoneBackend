<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryAttribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'attribute_type_id',
        'is_required',
        'is_filterable',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_filterable' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function attributeType()
    {
        return $this->belongsTo(AttributeType::class);
    }
}
