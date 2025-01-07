<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Search extends Model
{
    use HasFactory;

    const
    QUERY = 'query',
    USER_ID = 'user_id';

    protected $fillable = [
        self::QUERY,
        self::USER_ID
    ] ;

    public function user()
{
    return $this->belongsTo(User::class);
}
}
