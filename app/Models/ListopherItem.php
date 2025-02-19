<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListopherItem extends Model
{
    use HasFactory;
    public $timestamps = false;

    const
        ID = 'id',
        LISTOPHER_ID = 'listopher_id',
        POS = 'pos',
        B = 'b',
        H = 'h',
        SHAPE = 'shape',
        LENGTH = 'length',
        SIZE = 'size',
        HOOK = 'hook',
        OVERLAP = 'overlap',
        QUANTITY = 'quantity',
        LENGTH_TOTAL = 'length_total';

    protected $fillable = [
        self::LISTOPHER_ID,
        self::POS,
        self::B,
        self::H,
        self::SHAPE,
        self::LENGTH,
        self::SIZE,
        self::HOOK,
        self::OVERLAP,
        self::QUANTITY,
        self::LENGTH_TOTAL
    ];

    public function listopher(): BelongsTo
    {
        return $this->belongsTo(Listopher::class);
    }

}
