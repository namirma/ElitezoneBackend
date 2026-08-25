<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;

    /**
     * ⚠️ فهرستِ صریح، نه `$guarded = ['id']`.
     *
     * فاکتور یک سندِ مالی/مالیاتی است و `$guarded = ['id']` یعنی `amount`,
     * `status`, `user_id` و `invoice_number` همگی mass-assignable — بازترین
     * سیاستِ ممکن روی حساس‌ترین جدول، در کدبیسی که همه‌جای دیگرش `$fillable`
     * تنگ دارد. امروز فقط `InvoiceGenerator` می‌نویسد، ولی روزی که یک اندپوینت
     * ورودیِ کاربر را به `Invoice::create()` بدهد، مبلغ و مالکِ سند جعل‌شدنی
     * می‌شود. این هفت ستون دقیقاً همان‌هایی‌اند که `createInvoiceRecord()` می‌نویسد.
     */
    protected $fillable = [
        'order_id',
        'user_id',
        'document_code',
        'invoice_number',
        'amount',
        'file_path',
        'status',
    ];

    public const STATUS_ISSUED = 'issued';

    public const STATUS_VOID = 'void';

    /**
     * ارتباط با سفارش
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * ارتباط با کاربر (خریدار یا تامین‌کننده)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
