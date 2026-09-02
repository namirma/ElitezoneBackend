<?php

namespace App\Services;

use App\DataTransferObjects\Ticket\TicketDTO;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketFile;
use App\Models\TicketNote;
use App\Models\TicketReply;
use App\Models\User;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TicketService
{
    /**
     * پیامکِ هر گذارِ وضعیت — کلیدِ `lang/fa/sms.php` به‌ازای هر وضعیتِ فهرستِ
     * `ticketing.sms_on_status`.
     *
     * @var array<string, string>
     */
    private const STATUS_SMS_KEYS = [
        Ticket::STATUS_ANSWERED => 'ticket_admin_replied',
        Ticket::STATUS_RESOLVED => 'ticket_resolved',
        Ticket::STATUS_CLOSED => 'ticket_closed',
    ];

    public function __construct(protected TicketRepositoryInterface $repository) {}

    public function list()
    {
        return $this->repository->list();
    }

    /**
     * Create a ticket for the authenticated user, stamping the initial status and
     * SLA deadline. If an order is linked it must belong to the user.
     */
    public function create(TicketDTO $dto): Ticket
    {
        $userId = Auth::id();

        if ($dto->order_id !== null) {
            $this->assertOrderOwnedBy($dto->order_id, (int) $userId);
        }

        $data = $dto->toArray();
        $data['user_id'] = $userId;
        $data[Ticket::STATUS] = Ticket::STATUS_OPEN;
        $data[Ticket::SLA_DUE_AT] = $this->slaDeadlineFor($dto->priority);

        /** @var Ticket $ticket */
        $ticket = $this->repository->create($data);

        // Opening-message attachments (پیوست‌ها). Previously the DTO validated these
        // but they were silently dropped — they now persist against the ticket.
        foreach ($dto->file ?? [] as $file) {
            $path = $file->store(TicketFile::DIRECTORY, TicketFile::DISK);
            $ticket->files()->create([
                TicketFile::PATH => $path,
                TicketFile::ORIGINAL_NAME => $file->getClientOriginalName(),
                TicketFile::MIME_TYPE => $file->getMimeType() ?? $file->getClientMimeType(),
                TicketFile::SIZE => $file->getSize(),
            ]);
        }

        return $ticket;
    }

    public function getDepartments()
    {
        return $this->repository->getDepartments();
    }

    public function show(Ticket $ticket): Ticket
    {
        return $this->repository->show($ticket);
    }

    // =========================================================================
    //  Admin/staff operations
    // =========================================================================

    /** Claim/assign a ticket to a staff member (گرفتن/ارجاع). Idempotent on assigned_at. */
    public function assignTo(Ticket $ticket, int $adminId): Ticket
    {
        $ticket->forceFill([
            Ticket::ADMIN_ID => $adminId,
            Ticket::ASSIGNED_AT => $ticket->{Ticket::ASSIGNED_AT} ?? now(),
        ])->save();

        return $ticket;
    }

    /**
     * Move a ticket between departments (تغییر بخش)، حفظ بخش قبلی برای رهگیری.
     */
    public function moveToDepartment(Ticket $ticket, int $departmentId): Ticket
    {
        $ticket->forceFill([
            Ticket::PREVIOUS_DEPARTMENT_ID => $ticket->{Ticket::DEPARTMENT_ID},
            Ticket::DEPARTMENT_ID => $departmentId,
            Ticket::STATUS => Ticket::STATUS_IN_PROGRESS,
        ])->save();

        return $ticket;
    }

    /**
     * Transition a ticket's status, stamping the matching lifecycle timestamp and
     * counting re-openings for the KPI reopen-rate.
     *
     * @param  bool  $notify  ‏`false` فقط برای `reply()`: آنجا پیامکِ خودِ پاسخ
     *                        فرستاده می‌شود و گذارِ همزمان به `answered` نباید
     *                        پیامکِ دومی بسازد.
     */
    public function changeStatus(Ticket $ticket, string $newStatus, bool $notify = true): Ticket
    {
        $previous = $ticket->{Ticket::STATUS};

        $attrs = [Ticket::STATUS => $newStatus];

        // First staff response → stamp once (basis for the avg-first-response KPI).
        if ($newStatus === Ticket::STATUS_ANSWERED && $ticket->{Ticket::FIRST_RESPONDED_AT} === null) {
            $attrs[Ticket::FIRST_RESPONDED_AT] = now();
        }

        if ($newStatus === Ticket::STATUS_RESOLVED) {
            $attrs[Ticket::RESOLVED_AT] = now();
        }

        if ($newStatus === Ticket::STATUS_CLOSED) {
            $attrs[Ticket::CLOSED_AT] = now();
        }

        // Re-opening a resolved/closed ticket → count it and clear the end timestamps.
        $wasTerminal = in_array($previous, [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true);
        $nowOpen = ! in_array($newStatus, [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true);
        if ($wasTerminal && $nowOpen) {
            $attrs[Ticket::REOPENED_COUNT] = (int) $ticket->{Ticket::REOPENED_COUNT} + 1;
            $attrs[Ticket::RESOLVED_AT] = null;
            $attrs[Ticket::CLOSED_AT] = null;
        }

        $ticket->forceFill($attrs)->save();

        // ⚠️ `ticketing.sms_on_status` تا امروز **هیچ خواننده‌ای نداشت**.
        //
        // فهرست با ارجاعِ صریح به سند نوشته شده بود («اعلان‌ها فقط برای تغییر
        // وضعیت‌های مهم») و سه وضعیت داشت، ولی کنترلر به‌جای خواندنش دو شاخه‌ی
        // هاردکد داشت: پیامکِ پاسخ، و `=== STATUS_CLOSED`. یعنی از سه وضعیتِ
        // فهرست، **`resolved` هیچ‌وقت به کاربر خبر نمی‌داد** — تیکتش حل می‌شد و
        // خودش نمی‌فهمید.
        //
        // اینجا زندگی می‌کند چون تنها نویسنده‌ی وضعیتِ تیکت همین متد است؛ در
        // کنترلر یعنی هر مسیرِ تازه باید یادش بماند.
        if ($notify && $previous !== $newStatus) {
            $this->notifyOwnerOfStatusChange($ticket, $newStatus);
        }

        return $ticket;
    }

    private function notifyOwnerOfStatusChange(Ticket $ticket, string $newStatus): void
    {
        /** @var list<string> $notifiable */
        $notifiable = (array) config('ticketing.sms_on_status', []);

        if (! in_array($newStatus, $notifiable, true)) {
            return;
        }

        $smsKey = self::STATUS_SMS_KEYS[$newStatus] ?? null;

        // وضعیتی که به فهرست اضافه شده ولی متنِ پیامک ندارد: سکوت، نه ارسالِ
        // کلیدِ خام. `Localization/TranslationKeysResolveTest` نبودِ کلید را
        // جداگانه می‌گیرد.
        if ($smsKey === null) {
            return;
        }

        $mobile = $ticket->loadMissing('user')->user?->{User::MOBILE};

        if (! $mobile) {
            return;
        }

        SendSmsJob::dispatch(
            $mobile,
            __("sms.{$smsKey}", ['code' => $ticket->{Ticket::CODE}])
        );
    }

    /** Record the first staff response timestamp (called when an admin replies). */
    public function markFirstResponse(Ticket $ticket): void
    {
        if ($ticket->{Ticket::FIRST_RESPONDED_AT} === null) {
            $ticket->forceFill([Ticket::FIRST_RESPONDED_AT => now()])->save();
        }
    }

    /** Add a private internal note to a ticket (یادداشت تیکت). */
    public function addNote(Ticket $ticket, int $adminId, string $note): TicketNote
    {
        return $ticket->notes()->create([
            TicketNote::ADMIN_ID => $adminId,
            TicketNote::NOTE => $note,
        ]);
    }

    // =========================================================================
    //  KPIs (گزارش‌ها و KPI)
    // =========================================================================

    /**
     * Aggregate ticketing KPIs the spec asks for: volume by department, volume by
     * status, open/SLA-breach counts, average first-response & resolution times
     * (minutes), and the reopen rate. All derived with aggregate queries — no loops.
     *
     * @return array<string, mixed>
     */
    public function kpis(): array
    {
        $byDepartment = Ticket::query()
            ->select(Ticket::DEPARTMENT_ID, DB::raw('COUNT(*) as total'))
            ->groupBy(Ticket::DEPARTMENT_ID)
            ->pluck('total', Ticket::DEPARTMENT_ID);

        $byStatus = Ticket::query()
            ->select(Ticket::STATUS, DB::raw('COUNT(*) as total'))
            ->groupBy(Ticket::STATUS)
            ->pluck('total', Ticket::STATUS);

        $total = (int) Ticket::query()->count();
        $openCount = (int) Ticket::query()->open()->count();
        $slaBreaches = (int) Ticket::query()->breachingSla()->count();

        // Avg first-response & resolution in minutes (MySQL TIMESTAMPDIFF).
        $avgFirstResponseMinutes = (float) Ticket::query()
            ->whereNotNull(Ticket::FIRST_RESPONDED_AT)
            ->avg(DB::raw('TIMESTAMPDIFF(MINUTE, created_at, first_responded_at)'));

        $avgResolutionMinutes = (float) Ticket::query()
            ->whereNotNull(Ticket::RESOLVED_AT)
            ->avg(DB::raw('TIMESTAMPDIFF(MINUTE, created_at, resolved_at)'));

        $reopenedTickets = (int) Ticket::query()->where(Ticket::REOPENED_COUNT, '>', 0)->count();
        $reopenRate = $total > 0 ? round($reopenedTickets / $total, 4) : 0.0;

        // تیکت‌هایی که پنل هنوز ندیده است: یا هرگز باز نشده‌اند، یا پاسخی از
        // *کاربر* دارند که بعد از آخرین بازدیدِ ادمین ثبت شده. این عدد نشانگرِ
        // کنارِ آیتمِ منو را می‌سازد؛ بدون آن، ادمین باید تک‌تک تیکت‌ها را باز
        // می‌کرد تا بفهمد کدام پیام تازه دارد.
        $unreadTickets = (int) Ticket::query()
            ->whereNull(Ticket::ADMIN_LAST_READ_AT)
            ->orWhereHas('replies', function ($q) {
                $q->whereNull(TicketReply::ADMIN_ID)
                    ->whereColumn(
                        'ticket_replies.created_at',
                        '>',
                        'tickets.'.Ticket::ADMIN_LAST_READ_AT
                    );
            })
            ->count();

        return [
            'total' => $total,
            'open' => $openCount,
            'unread' => $unreadTickets,
            'sla_breaches' => $slaBreaches,
            'by_department' => $byDepartment,
            'by_status' => $byStatus,
            'avg_first_response_minutes' => round($avgFirstResponseMinutes, 1),
            'avg_resolution_minutes' => round($avgResolutionMinutes, 1),
            'reopen_rate' => $reopenRate,
        ];
    }

    // =========================================================================
    //  Internals
    // =========================================================================

    private function slaDeadlineFor(string $priority): \Illuminate\Support\Carbon
    {
        $hours = (int) config("ticketing.sla_hours.{$priority}", config('ticketing.sla_hours.normal', 24));

        // ساعت‌های SLA فقط داخل تقویم کاری مصرف می‌شوند (BusinessHours);
        // با business_hours.enabled=false همان wall-clock سابق است.
        return \App\Support\BusinessHours::addWorkingHours(now(), $hours);
    }

    /**
     * @throws AuthorizationException when the order is not owned by the user.
     */
    private function assertOrderOwnedBy(int $orderId, int $userId): void
    {
        $owns = Order::query()
            ->whereKey($orderId)
            ->where(fn ($q) => $q
                ->where(Order::PLACER_USER_ID, $userId)
                ->orWhere(Order::BENEFICIARY_USER_ID, $userId))
            ->exists();

        if (! $owns) {
            throw new AuthorizationException(__('ticket.order_not_owned'));
        }
    }
}
