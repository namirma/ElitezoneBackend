<?php

namespace App\Http\Resources\Ticket;

use App\Http\Resources\BaseJsonResource;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * یادداشت داخلی تیم پشتیبانی روی یک تیکت.
 * ⚠️ فقط در پاسخ‌های ادمین سریالایز می‌شود — هرگز برای صاحب تیکت.
 *
 * @mixin TicketNote
 */
class TicketNoteResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $admin = $this->whenLoaded('admin');
        $adminName = null;
        if ($admin instanceof User) {
            $profile = $admin->relationLoaded('profile') ? $admin->profile : null;
            $adminName = trim(($profile->name ?? '').' '.($profile->family ?? '')) ?: $admin->mobile;
        }

        return [
            'id' => $this->{TicketNote::ID},
            'note' => $this->{TicketNote::NOTE},
            'admin_id' => $this->{TicketNote::ADMIN_ID},
            'admin_name' => $adminName,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
