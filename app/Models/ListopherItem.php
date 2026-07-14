<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $listopher_id
 * @property string $pos
 * @property string $shape
 * @property numeric $length
 * @property string $size
 * @property numeric|null $hook
 * @property numeric|null $overlap
 * @property int $quantity
 * @property numeric|null $length_total
 * @property numeric|null $b
 * @property numeric|null $h
 * @property-read \App\Models\Listopher $listopher
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereB($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereH($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereHook($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereLength($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereLengthTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereListopherId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereOverlap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem wherePos($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereShape($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereSize($value)
 * @mixin \Eloquent
 */
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
