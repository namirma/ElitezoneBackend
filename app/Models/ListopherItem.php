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
 * @property string $length
 * @property string $size
 * @property string|null $hook
 * @property string|null $overlap
 * @property int $quantity
 * @property string|null $length_total
 * @property string|null $b
 * @property string|null $h
 * @property-read \App\Models\Listopher $listopher
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem query()
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereB($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereH($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereHook($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereLength($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereLengthTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereListopherId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereOverlap($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem wherePos($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereShape($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereSize($value)
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
