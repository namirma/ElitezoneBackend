<?php

namespace App\Http\Resources\Ticket;

use App\Enums\Shared\StatusTone;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\Ticket
 */
class TicketResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $statusKey = $this->status;

        $statusLabel = match ($statusKey) {
            'open' => 'باز',
            'in_progress' => 'در حال بررسی',
            'waiting_customer' => 'در انتظار مشتری',
            'waiting_supplier' => 'در انتظار تأمین‌کننده',
            'answered' => 'پاسخ داده شده',
            'resolved' => 'حل‌شده',
            'pending' => 'در انتظار پاسخ',
            'closed' => 'بسته شده',
            default => $statusKey,
        };

        $priorityKey = $this->priority;
        $priorityLabel = match ($priorityKey) {
            'urgent' => 'فوری',
            'high' => 'بالا',
            'normal' => 'عادی',
            'low' => 'پایین',
            default => $priorityKey,
        };

        return [
            'id' => $this->id,
            'code' => $this->code,
            'subject' => $this->subject,
            'message' => $this->message,

            // status_info — ساختار یکپارچه با بقیه Resourceها
            'status_info' => [
                'key' => $statusKey,
                'label' => $statusLabel,
                'tone' => StatusTone::fromStatus($statusKey)->value,
            ],
            // حفظ فیلد قدیمی برای سازگاری عقبگرد
            'status' => $statusKey,

            'priority' => [
                'key' => $priorityKey,
                'label' => $priorityLabel,
            ],

            'order_id' => $this->order_id,
            'province_id' => $this->province_id,
            'city_id' => $this->city_id,

            // SLA & lifecycle
            'sla_due_at' => $this->sla_due_at?->toDateTimeString(),
            'sla_breached' => $this->sla_due_at !== null
                && in_array($statusKey, (array) config('ticketing.open_statuses', []), true)
                && $this->sla_due_at->isPast(),
            'assigned_at' => $this->assigned_at?->toDateTimeString(),
            'first_responded_at' => $this->first_responded_at?->toDateTimeString(),
            'resolved_at' => $this->resolved_at?->toDateTimeString(),
            'closed_at' => $this->closed_at?->toDateTimeString(),
            // EscalateOverdueTicketsJob این را پر می‌کند؛ قبلاً serialize نمی‌شد.
            'escalated_at' => $this->escalated_at?->toDateTimeString(),
            'reopened_count' => (int) $this->reopened_count,

            'created_at' => $this->created_at?->toDateTimeString(),
            'user' => $this->user,

            // کارشناسِ اختصاص‌یافته (assign) — پنل باید بداند تیکت الان دست کیست.
            'admin_id' => $this->admin_id,

            'department' => new DepartmentResource($this->whenLoaded('department')),
            'attachments' => TicketFileResource::collection($this->whenLoaded('files')),
            'replies' => TicketReplyResource::collection($this->whenLoaded('replies')),

            // یادداشت‌های داخلی — فقط ادمین. صاحبِ تیکت هرگز نباید ببیندشان، پس
            // علاوه بر eager-load نشدن در روت‌های کاربر، اینجا هم گیتِ نقش هست.
            'notes' => $this->when(
                (bool) $request->user()?->hasRole('admin'),
                fn () => TicketNoteResource::collection($this->whenLoaded('notes'))
            ),
        ];
    }
}
