<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'description',
        'is_available_for_ticket',
    ];

    public function scopeIsAvailableForTicket($query)
    {
        return $query->where('is_available_for_ticket', true);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
