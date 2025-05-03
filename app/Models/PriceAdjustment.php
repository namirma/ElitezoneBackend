<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceAdjustment extends Model
{
    protected $fillable = [
        'customer_type_id', 'badge_id', 'adjustment_amount', 'is_active'
    ];

    public function customerType(): BelongsTo
    {
        return $this->belongsTo(CustomerType::class);
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
