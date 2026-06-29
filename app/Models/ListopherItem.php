<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListopherItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    const ID = 'id';

    const LISTOPHER_ID = 'listopher_id';

    const POS = 'pos';

    const B = 'b';

    const H = 'h';

    const SHAPE = 'shape';

    const LENGTH = 'length';

    const SIZE = 'size';

    const HOOK = 'hook';

    const OVERLAP = 'overlap';

    const QUANTITY = 'quantity';

    const LENGTH_TOTAL = 'length_total';

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
        self::LENGTH_TOTAL,
    ];

    public function listopher(): BelongsTo
    {
        return $this->belongsTo(Listopher::class);
    }
}
