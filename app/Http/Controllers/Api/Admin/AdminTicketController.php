<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminTicketReplyRequest;
use App\Http\Requests\Admin\AdminTicketStatusRequest;
use App\Http\Resources\Ticket\DepartmentResource;
use App\Http\Resources\Ticket\TicketResource;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — مدیریت تیکت‌های پشتیبانی
 *
 * وضعیت‌های تیکت: open | in_progress | waiting_customer | waiting_supplier |
 *                 answered | resolved | closed  (+ pending legacy)
 */
class AdminTicketController extends Controller
{
    public function __construct(protected TicketService $service) {}

    /**
     * لیست تیکت‌ها با فیلتر وضعیت، دپارتمان و جستجوی متنی
     *
     * Query params:
     *   status (optional) — فیلتر بر اساس وضعیت
     *   department_id (optional) — فیلتر بر اساس دپارتمان
     *   search (optional) — جستجو در موضوع، کد و نام کاربر
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', Ticket::STATUSES)],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'min:2'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tickets = Ticket::query()
            ->with(['user.profile', 'department', 'replies'])
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where('status', $request->status)
            )
            ->when(
                $request->filled('department_id'),
                fn (Builder $q) => $q->where('department_id', $request->integer('department_id'))
            )
            ->when(
                $request->filled('search'),
                fn (Builder $q) => $q->where(
                    fn ($sub) => $sub
                        ->where('subject', 'like', "%{$request->search}%")
                        ->orWhere('code', 'like', "%{$request->search}%")
                        ->orWhereHas('user.profile', fn ($p) => $p->where('name', 'like', "%{$request->search}%"))
                )
            )
            ->latest()
            ->paginate($this->perPage());

        return success(TicketResource::collection($tickets), __('admin.tickets.list_received'));
    }

    /**
     * جزئیات کامل یک تیکت به همراه تمام پاسخ‌ها
     */
    public function show(Ticket $ticket): JsonResponse
    {
        $ticket->load(['user.profile', 'department', 'files', 'replies.user.profile', 'replies.files']);

        return success(new TicketResource($ticket), __('global.received_successfully'));
    }

    /**
     * ارسال پاسخ ادمین به تیکت
     *
     * - یک TicketReply با admin_id ایجاد می‌کند
     * - وضعیت تیکت به 'answered' تغییر می‌کند
     * - تیکت به این ادمین اختصاص (assign) می‌شود
     * - SMS اطلاع‌رسانی به کاربر ارسال می‌شود
     */
    public function reply(AdminTicketReplyRequest $request, Ticket $ticket): JsonResponse
    {
        $ticket->replies()->create([
            'message' => $request->validated('message'),
            'admin_id' => Auth::id(),
        ]);

        // Assign to the responding admin (stamps assigned_at once) and move the
        // status to "answered" — which stamps first_responded_at for the SLA KPI.
        $this->service->assignTo($ticket, (int) Auth::id());
        $this->service->changeStatus($ticket, Ticket::STATUS_ANSWERED);

        $ticket->load(['user.profile', 'department', 'files', 'replies.user.profile', 'replies.files']);

        // SMS اطلاع‌رسانی به کاربر تیکت
        $this->notifyTicketUser($ticket, 'ticket_admin_replied');

        return success(new TicketResource($ticket), __('admin.tickets.reply_sent'));
    }

    /**
     * تغییر وضعیت تیکت توسط ادمین
     *
     * وضعیت‌های مجاز: open | answered | pending | closed
     * SMS فقط برای بستن تیکت ارسال می‌شود (رویداد مهم برای کاربر).
     */
    public function changeStatus(AdminTicketStatusRequest $request, Ticket $ticket): JsonResponse
    {
        $newStatus = $request->validated('status');

        // Delegate to the service so lifecycle timestamps (resolved_at / closed_at /
        // first_responded_at) and the reopen counter are stamped consistently.
        $this->service->changeStatus($ticket, $newStatus);

        $message = match ($newStatus) {
            Ticket::STATUS_CLOSED => __('admin.tickets.closed'),
            Ticket::STATUS_OPEN => __('admin.tickets.reopened'),
            Ticket::STATUS_PENDING => __('admin.tickets.set_pending'),
            Ticket::STATUS_ANSWERED => __('admin.tickets.marked_answered'),
            default => __('global.updated_successfully'),
        };

        // SMS فقط وقتی تیکت بسته می‌شود — رویداد پایانی برای کاربر
        if ($newStatus === Ticket::STATUS_CLOSED) {
            $ticket->loadMissing('user');
            $this->notifyTicketUser($ticket, 'ticket_closed');
        }

        return success(null, $message);
    }

    /**
     * افزودن یادداشت داخلی به تیکت (یادداشت‌های تیکت — فقط برای تیم پشتیبانی).
     */
    public function addNote(Request $request, Ticket $ticket): JsonResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $this->service->addNote($ticket, (int) Auth::id(), $request->string('note')->toString());

        return success(null, __('ticket.note_added'));
    }

    /**
     * KPIهای تیکتینگ: حجم به تفکیک دسته/وضعیت، تعداد باز و نقض SLA،
     * میانگین زمان اولین پاسخ و حل، و نرخ بازگشایی.
     */
    public function kpis(): JsonResponse
    {
        return success($this->service->kpis(), __('global.received_successfully'));
    }

    /**
     * اختصاص تیکت به یک کارشناسِ ادمین (Assignment طبق spec).
     */
    public function assign(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['admin_id' => ['required', 'integer', 'exists:users,id']]);

        if (! User::find($data['admin_id'])?->hasRole('admin')) {
            return error(__('admin.tickets.assignee_not_admin'), [], 422);
        }

        $this->service->assignTo($ticket, (int) $data['admin_id']);

        return success(new TicketResource($ticket->fresh(['user.profile', 'department'])), __('admin.tickets.assigned'));
    }

    /**
     * انتقال تیکت به دپارتمان دیگر (ارجاع مرجوعی/داوری/مالی به بخشِ مربوطه).
     */
    public function moveDepartment(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['department_id' => ['required', 'integer', 'exists:departments,id']]);

        $this->service->moveToDepartment($ticket, (int) $data['department_id']);

        return success(new TicketResource($ticket->fresh(['user.profile', 'department'])), __('admin.tickets.department_moved'));
    }

    /**
     * تغییر اولویتِ تیکت (Escalation — مثلاً بالا بردن به urgent).
     */
    public function setPriority(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['priority' => ['required', Rule::in(Ticket::PRIORITIES)]]);

        $ticket->update([Ticket::PRIORITY => $data['priority']]);

        return success(new TicketResource($ticket->fresh(['user.profile', 'department'])), __('admin.tickets.priority_updated'));
    }

    /**
     * لیست دپارتمان‌های قابل‌انتخاب برای اختصاص/انتقالِ تیکت.
     */
    public function departments(): JsonResponse
    {
        return success(DepartmentResource::collection($this->service->getDepartments()), __('admin.tickets.departments_received'));
    }

    /**
     * ارسال SMS اطلاع‌رسانی به صاحب تیکت.
     *
     * @param  string  $smsKey  — کلید lang/fa/sms.php
     */
    private function notifyTicketUser(Ticket $ticket, string $smsKey): void
    {
        $user = $ticket->user;
        if (! $user instanceof User) {
            return;
        }

        $mobile = $user->{User::MOBILE};
        if (! $mobile) {
            return;
        }

        SendSmsJob::dispatch(
            $mobile,
            __("sms.{$smsKey}", ['code' => $ticket->{Ticket::CODE}])
        );
    }
}
