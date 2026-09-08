<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\BaseJsonResource;
use App\Models\Badge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Badge
 */
class BadgeResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Badge::ID => $this->id,
            Badge::NAME => $this->name,
            // getAttribute و نه دسترسی جادویی — ستون `description` تازه اضافه
            // شده و larastan بدون فایل‌های ide-helper (که gitignore شده‌اند)
            // نمی‌شناسدش. همان الگویی که بقیه‌ی Resourceها برای ستون‌های تازه
            // دارند.
            Badge::DESCRIPTION => $this->getAttribute(Badge::DESCRIPTION),
            Badge::CODE => $this->code,
            // Full public URL (icons live on the public disk) so the frontend can <img src> it.
            Badge::ICON => $this->icon
                ? (str_starts_with($this->icon, 'http') ? $this->icon : Storage::disk('public')->url($this->icon))
                : null,
            Badge::CREATED_AT => $this->created_at?->format('Y-m-d H:i:s'),
            Badge::UPDATED_AT => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
