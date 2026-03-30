<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use SoftDeletes;

    protected $with = ['department'];

    protected $fillable = [
        'code',
        'user_id',
        'department_id',
        'previous_department_id',
        'admin_id',
        'subject',
        'message',
        'status'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }
}
