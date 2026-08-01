<?php

namespace App\Repositories;

use App\Models\Department;
use App\Models\Ticket;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class TicketRepository implements TicketRepositoryInterface
{
    public function query(): Builder
    {
        return Ticket::query();
    }

    public function create(array $data): Model|Builder
    {
        return $this->query()->create($data);
    }

    public function getDepartments()
    {
        return Department::IsAvailableForTicket()->get();
    }

    public function list(): Collection
    {
        return $this->query()
            ->where('user_id', Auth::id())
            // `replies` لازم است: `TicketResource` شمارِ «پاسخ‌های خوانده‌نشده»
            // را از همین رابطه می‌سازد و بدون لود بودنش بی‌صدا صفر برمی‌گرداند —
            // یعنی کاربر هیچ‌وقت نمی‌فهمید پشتیبانی جواب داده است.
            // `replies.admin.profile` هم لازم است: از وقتی پاسخِ پشتیبانی نام و
            // تصویرِ واقعیِ کارشناس را نشان می‌دهد، نبودش یعنی یک کوئریِ اضافه
            // به‌ازای هر پاسخ در هر تیکتِ فهرست.
            ->with(['department', 'replies', 'replies.admin.profile'])
            ->latest()
            ->get();
    }

    public function show(Ticket $ticket): Ticket
    {
        return $ticket->load([
            'department', 'user', 'files', 'replies.user', 'replies.files',
            // مسیرِ اصلیِ دیدنِ هویتِ پشتیبان توسط مشتری همین است.
            'replies.admin.profile',
        ]);
    }
}
