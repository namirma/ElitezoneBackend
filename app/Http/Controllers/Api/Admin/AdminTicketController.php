<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminTicketReplyRequest;
use App\Http\Requests\Admin\AdminTicketStatusRequest;
use App\Http\Resources\Ticket\TicketResource;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * پنل مدیریت — مدیریت تیکت‌های پشتیبانی
 *
 * وضعیت‌های تیکت: open | answered | pending | closed
 */
class AdminTicketController extends Controller
{
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
            'status'        => ['nullable', 'string', 'in:open,answered,pending,closed'],
            'department_id' => ['nullable', 'integer'],
            'search'        => ['nullable', 'string', 'min:2'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
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
        $ticket->load(['user.profile', 'department', 'replies.user.profile']);

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
            'message'  => $request->validated('message'),
            'admin_id' => Auth::id(),
        ]);

        $ticket->update([
            Ticket::STATUS   => Ticket::STATUS_ANSWERED,
            Ticket::ADMIN_ID => Auth::id(),
        ]);

        $ticket->load(['user.profile', 'department', 'replies.user.profile']);

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
        $ticket->update([Ticket::STATUS => $newStatus]);

        $message = match ($newStatus) {
            Ticket::STATUS_CLOSED   => __('admin.tickets.closed'),
            Ticket::STATUS_OPEN     => __('admin.tickets.reopened'),
            Ticket::STATUS_PENDING  => __('admin.tickets.set_pending'),
            Ticket::STATUS_ANSWERED => __('admin.tickets.marked_answered'),
            default                 => __('global.updated_successfully'),
        };

        // SMS فقط وقتی تیکت بسته می‌شود — رویداد پایانی برای کاربر
        if ($newStatus === Ticket::STATUS_CLOSED) {
            $ticket->loadMissing('user');
            $this->notifyTicketUser($ticket, 'ticket_closed');
        }

        return success(null, $message);
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
