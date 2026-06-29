<?php

namespace App\Observers;

use App\Models\Ticket;

class TicketObserver
{
    /**
     * کد یکتای تیکت را بعد از ایجاد ست می‌کند.
     *
     * فرمت: TCK-XXXXXX  (صفر-پَد تا ۶ رقم)
     * مثال: TCK-000001، TCK-001234، TCK-100000
     *
     * چرا after-create (نه before-create)؟
     *   - `id` فقط بعد از INSERT وجود دارد.
     *   - saveQuietly() از trigger شدن مجدد Observer جلوگیری می‌کند.
     */
    public function created(Ticket $ticket): void
    {
        $ticket->{Ticket::CODE} = Ticket::CODE_PREFIX . str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT);
        $ticket->saveQuietly();
    }
}
