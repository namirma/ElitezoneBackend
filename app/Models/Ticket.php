<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use SoftDeletes;

    // ── Column constants ──────────────────────────────────────────────────
    public const ID = 'id';

    public const CODE = 'code';

    public const USER_ID = 'user_id';

    public const ORDER_ID = 'order_id';

    public const PROVINCE_ID = 'province_id';

    public const CITY_ID = 'city_id';

    public const DEPARTMENT_ID = 'department_id';

    public const PREVIOUS_DEPARTMENT_ID = 'previous_department_id';

    public const ADMIN_ID = 'admin_id';

    public const SUBJECT = 'subject';

    public const MESSAGE = 'message';

    public const STATUS = 'status';

    public const PRIORITY = 'priority';

    public const ASSIGNED_AT = 'assigned_at';

    public const FIRST_RESPONDED_AT = 'first_responded_at';

    public const RESOLVED_AT = 'resolved_at';

    public const CLOSED_AT = 'closed_at';

    public const SLA_DUE_AT = 'sla_due_at';

    public const ESCALATED_AT = 'escalated_at';

    public const REOPENED_COUNT = 'reopened_count';

    /** آخرین باری که یک ادمین این تیکت را باز کرد (صف مشترک — نه per-admin). */
    public const ADMIN_LAST_READ_AT = 'admin_last_read_at';

    /** آخرین باری که صاحب تیکت آن را باز کرد. */
    public const USER_LAST_READ_AT = 'user_last_read_at';

    // ── Status values (full helpdesk state machine) ───────────────────────
    public const STATUS_OPEN = 'open';              // New / باز

    public const STATUS_IN_PROGRESS = 'in_progress';       // ارجاع‌شده/در حال بررسی

    public const STATUS_WAITING_CUSTOMER = 'waiting_customer';  // در انتظار مشتری

    public const STATUS_WAITING_SUPPLIER = 'waiting_supplier';  // در انتظار تأمین‌کننده

    public const STATUS_ANSWERED = 'answered';          // پاسخ داده شده

    public const STATUS_RESOLVED = 'resolved';          // حل‌شده

    public const STATUS_CLOSED = 'closed';            // بسته‌شده

    public const STATUS_PENDING = 'pending';           // legacy — kept for BC

    /** All statuses accepted on input/transition. */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING_CUSTOMER,
        self::STATUS_WAITING_SUPPLIER,
        self::STATUS_ANSWERED,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
        self::STATUS_PENDING,
    ];

    // ── Priority values ───────────────────────────────────────────────────
    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    // ── TCK code prefix ───────────────────────────────────────────────────
    public const CODE_PREFIX = 'TCK-';

    protected $fillable = [
        self::CODE,
        self::USER_ID,
        self::ORDER_ID,
        self::PROVINCE_ID,
        self::CITY_ID,
        self::DEPARTMENT_ID,
        self::PREVIOUS_DEPARTMENT_ID,
        self::ADMIN_ID,
        self::SUBJECT,
        self::MESSAGE,
        self::STATUS,
        self::PRIORITY,
        self::ASSIGNED_AT,
        self::FIRST_RESPONDED_AT,
        self::RESOLVED_AT,
        self::CLOSED_AT,
        self::SLA_DUE_AT,
        self::REOPENED_COUNT,
    ];

    protected $casts = [
        self::ASSIGNED_AT => 'datetime',
        self::FIRST_RESPONDED_AT => 'datetime',
        self::RESOLVED_AT => 'datetime',
        self::CLOSED_AT => 'datetime',
        self::SLA_DUE_AT => 'datetime',
        self::ESCALATED_AT => 'datetime',
        self::REOPENED_COUNT => 'integer',
        self::ADMIN_LAST_READ_AT => 'datetime',
        self::USER_LAST_READ_AT => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, self::ORDER_ID);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, self::ADMIN_ID);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    // ── «خوانده‌نشده» ─────────────────────────────────────────────────────
    //
    // یک تعریفِ واحد برای هر دو کلاینت. اگر پنل و سایت هرکدام منطق خودشان را
    // بنویسند، دیر یا زود یکی «۲ پیام جدید» می‌گوید و دیگری هیچ.
    //
    // قاعده: پیامی که *طرفِ مقابل* فرستاده و بعد از آخرین بازدیدِ من ثبت شده.
    // `null` یعنی هرگز باز نشده → همه‌چیز خوانده‌نشده است.

    /** پاسخ‌های کاربر که ادمین هنوز ندیده است. */
    public function unreadForAdminCount(): int
    {
        return $this->replies
            ->filter(fn (TicketReply $r) => $r->{TicketReply::ADMIN_ID} === null
                && $this->isAfterRead($r, $this->{self::ADMIN_LAST_READ_AT}))
            ->count();
    }

    /** پاسخ‌های پشتیبانی که کاربر هنوز ندیده است. */
    public function unreadForUserCount(): int
    {
        return $this->replies
            ->filter(fn (TicketReply $r) => $r->{TicketReply::ADMIN_ID} !== null
                && $this->isAfterRead($r, $this->{self::USER_LAST_READ_AT}))
            ->count();
    }

    /**
     * خودِ تیکتِ تازه هم برای ادمین «خوانده‌نشده» است — حتی وقتی هیچ پاسخی ندارد.
     * بدون این، یک تیکتِ کاملاً جدید هیچ نشانه‌ای در پنل نمی‌گرفت.
     */
    public function isUnreadForAdmin(): bool
    {
        return $this->{self::ADMIN_LAST_READ_AT} === null || $this->unreadForAdminCount() > 0;
    }

    private function isAfterRead(TicketReply $reply, ?\Illuminate\Support\Carbon $readAt): bool
    {
        if ($readAt === null) {
            return true;
        }

        $createdAt = $reply->{TicketReply::CREATED_AT};

        return $createdAt !== null && $createdAt->greaterThan($readAt);
    }

    /**
     * ادمین تیکت را باز کرد.
     *
     * ⚠️ عمداً فقط ردیفِ دیتابیس را به‌روز می‌کند و **مقدارِ در-حافظه را دست
     * نمی‌زند**. دلیلش ترتیبِ ارزیابی است: `TicketResource` تنبل است و `toArray()`
     * آن موقعِ سریالایزِ پاسخ اجرا می‌شود — یعنی *بعد* از این متد. اگر اینجا
     * `$this` را هم به‌روز کنیم، همان پاسخی که قرار است بگوید «۲ پیام جدید»
     * صفر برمی‌گرداند و ادمین هرگز نمی‌فهمد چه چیزی تازه بود.
     *
     * پس: پاسخِ همین درخواست شمارِ پیش‌از-خواندن را نشان می‌دهد، درخواست بعدی صفر.
     * (این را `TicketUnreadTest::the_admin_show_response_still_reports_...` می‌گیرد.)
     */
    public function markReadByAdmin(): void
    {
        static::whereKey($this->getKey())->update([self::ADMIN_LAST_READ_AT => now()]);
    }

    /** صاحب تیکت آن را باز کرد. همان ملاحظه‌ی ترتیبِ ارزیابیِ بالا. */
    public function markReadByUser(): void
    {
        static::whereKey($this->getKey())->update([self::USER_LAST_READ_AT => now()]);
    }

    /** Attachments on the ticket's opening message (پیوست‌های پیام اولیه). */
    public function files(): HasMany
    {
        return $this->hasMany(TicketFile::class, TicketFile::TICKET_ID);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(TicketNote::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /** Tickets not yet resolved or closed. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn(self::STATUS, (array) config('ticketing.open_statuses', []));
    }

    /** Open tickets whose SLA deadline has passed. */
    public function scopeBreachingSla(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull(self::SLA_DUE_AT)
            ->where(self::SLA_DUE_AT, '<', now());
    }
}
