<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserType extends Model
{
    use HasFactory;

    public $timestamps = false;

    const ID = 'id';

    const NAME = 'name';

    const SLUG = 'slug';

    const ICON = 'icon';

    protected $fillable = [
        self::NAME,
        self::SLUG,
        self::ICON,
    ];

    public function user(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
