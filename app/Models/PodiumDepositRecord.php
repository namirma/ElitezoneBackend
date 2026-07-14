<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $doc_number
 * @property string|null $shenaseh_variz
 * @property string|null $sender_iban شبای فرستنده از ItemSystemMetaData پادیوم
 * @property string|null $sender_name نام فرستنده از ItemSystemMetaData پادیوم
 * @property string|null $national_code
 * @property int $creditor_amount
 * @property int|null $user_id
 * @property int|null $wallet_txn_id
 * @property string|null $matched_by روش تطبیق: shenaseh | iban | null
 * @property string|null $deposited_at_jalali
 * @property Carbon|null $processed_at
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\WalletTransaction|null $walletTransaction
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereCreditorAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereDepositedAtJalali($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereDocNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereMatchedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereProcessedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereSenderIban($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereSenderName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereShenasehVariz($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereWalletTxnId($value)
 * @mixin \Eloquent
 */
class PodiumDepositRecord extends Model
{
    // ── ثابت‌های نام ستون‌ها ────────────────────────────────────────────────
    public const ID = 'id';

    public const DOC_NUMBER = 'doc_number';

    public const SHENASEH_VARIZ = 'shenaseh_variz';

    public const SENDER_IBAN = 'sender_iban';

    public const SENDER_NAME = 'sender_name';

    public const NATIONAL_CODE = 'national_code';

    public const CREDITOR_AMOUNT = 'creditor_amount';

    public const USER_ID = 'user_id';

    public const WALLET_TXN_ID = 'wallet_txn_id';

    public const MATCHED_BY = 'matched_by';

    public const DEPOSITED_AT_JALALI = 'deposited_at_jalali';

    public const PROCESSED_AT = 'processed_at';

    public const NOTE = 'note';

    /** روش‌های تطبیق واریز با کاربر */
    public const MATCHED_BY_SHENASEH = 'shenaseh'; // از طریق شناسه واریز (۱۱ رقم عددی)

    public const MATCHED_BY_IBAN = 'iban';      // از طریق شبای فرستنده ↔ UserBankAccount

    public const MATCHED_BY_ADMIN = 'admin';     // شارژ دستی توسط ادمین

    protected $fillable = [
        self::DOC_NUMBER,
        self::SHENASEH_VARIZ,
        self::SENDER_IBAN,
        self::SENDER_NAME,
        self::NATIONAL_CODE,
        self::CREDITOR_AMOUNT,
        self::USER_ID,
        self::WALLET_TXN_ID,
        self::MATCHED_BY,
        self::DEPOSITED_AT_JALALI,
        self::PROCESSED_AT,
        self::NOTE,
    ];

    protected $casts = [
        self::PROCESSED_AT => 'datetime',
        self::CREDITOR_AMOUNT => 'integer',
    ];

    // ── Relations ────────────────────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::USER_ID);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, self::WALLET_TXN_ID);
    }
}
