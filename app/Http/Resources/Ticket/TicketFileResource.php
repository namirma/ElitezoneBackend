<?php

namespace App\Http\Resources\Ticket;

use App\Http\Resources\BaseJsonResource;
use App\Models\TicketFile;
use Illuminate\Http\Request;

/**
 * @mixin TicketFile
 */
class TicketFileResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            // URLِ نامحافظت‌شده‌ی دیسک public جایش را به روتِ دانلودِ احراز
            // هویت‌شده داد — پیوست تیکت داده‌ی خصوصیِ کاربر است و نباید با
            // داشتنِ صرفِ لینک قابل دریافت باشد.
            'url' => route('ticket.file.download', ['ticketFile' => $this->id], false),
            'size' => $this->size,
            'mime_type' => $this->mime_type,
        ];
    }
}
