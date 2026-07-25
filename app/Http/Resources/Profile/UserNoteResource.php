<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\BaseJsonResource;
use App\Models\UserNote;
use Illuminate\Http\Request;

/**
 * یادداشت داخلیِ ادمین روی یک کاربر (بک‌آفیس).
 * فقط در پاسخ‌های ادمین سریالایز می‌شود — هرگز به خود کاربر نشان داده نمی‌شود.
 *
 * @mixin \App\Models\UserNote
 */
class UserNoteResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $admin = $this->whenLoaded('admin') instanceof \App\Models\User ? $this->admin : null;
        $adminProfile = $admin?->relationLoaded('profile') ? $admin->profile : null;

        return [
            UserNote::ID => $this->{UserNote::ID},
            UserNote::NOTE => $this->{UserNote::NOTE},
            'admin_id' => $this->{UserNote::ADMIN_ID},
            'admin_name' => $adminProfile
                ? trim(($adminProfile->name ?? '').' '.($adminProfile->family ?? '')) ?: $admin?->mobile
                : null,
            'created_at' => $this->{UserNote::CREATED_AT}?->toDateTimeString(),
            'created_at_p' => $this->created_at_p,
        ];
    }
}
