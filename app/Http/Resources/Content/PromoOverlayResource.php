<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\PromoOverlay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Models\PromoOverlay
 */
class PromoOverlayResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            PromoOverlay::ID => $this->{PromoOverlay::ID},
            PromoOverlay::KEY => $this->{PromoOverlay::KEY},
            PromoOverlay::TITLE => $this->{PromoOverlay::TITLE},
            PromoOverlay::BODY => $this->{PromoOverlay::BODY},

            // مسیرِ خام برای فرمِ ادمین + آدرسِ کاملِ قابل نمایش برای سایت.
            // بدونِ آدرسِ کامل، فرانت باید خودش دیسک را حدس بزند.
            PromoOverlay::IMAGE => $this->{PromoOverlay::IMAGE},
            'image_url' => $this->{PromoOverlay::IMAGE}
                ? Storage::disk('public')->url($this->{PromoOverlay::IMAGE})
                : null,

            PromoOverlay::BUTTONS => $this->{PromoOverlay::BUTTONS} ?? [],
            PromoOverlay::AUDIENCE => $this->{PromoOverlay::AUDIENCE},
            PromoOverlay::ROUTES => $this->{PromoOverlay::ROUTES},
            PromoOverlay::DELAY_SECONDS => $this->{PromoOverlay::DELAY_SECONDS},
            PromoOverlay::FREQUENCY => $this->{PromoOverlay::FREQUENCY},
            PromoOverlay::FREQUENCY_TTL_HOURS => $this->{PromoOverlay::FREQUENCY_TTL_HOURS},
            PromoOverlay::PRIORITY => $this->{PromoOverlay::PRIORITY},
            PromoOverlay::IS_ACTIVE => (bool) $this->{PromoOverlay::IS_ACTIVE},

            // اورلیِ سیستمی حذف نمی‌شود؛ پنل باید دکمه‌ی حذف را غیرفعال کند.
            'is_system' => $this->isSystem(),

            'created_at_p' => $this->created_at_p,
        ];
    }
}
