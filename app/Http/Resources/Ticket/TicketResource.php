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
            'open'     => 'باز',
            'answered' => 'پاسخ داده شده',
            'pending'  => 'در انتظار پاسخ',
            'closed'   => 'بسته شده',
            default    => $statusKey,
        };

        return [
            'id'      => $this->id,
            'code'    => $this->code,
            'subject' => $this->subject,
            'message' => $this->message,

            // status_info — ساختار یکپارچه با بقیه Resourceها
            'status_info' => [
                'key'   => $statusKey,
                'label' => $statusLabel,
                'tone'  => StatusTone::fromStatus($statusKey)->value,
            ],
            // حفظ فیلد قدیمی برای سازگاری عقبگرد
            'status' => $statusKey,

            'created_at' => $this->created_at?->toDateTimeString(),
            'user'       => $this->user,

            'department'  => new DepartmentResource($this->whenLoaded('department')),
            'attachments' => TicketFileResource::collection($this->whenLoaded('files')),
            'replies'     => TicketReplyResource::collection($this->whenLoaded('replies')),
        ];
    }
}
