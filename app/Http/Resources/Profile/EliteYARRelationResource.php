<?php

namespace App\Http\Resources\Profile;

use App\Enums\Shared\StatusTone;
use App\Http\Resources\BaseJsonResource;
use App\Models\EmployerEliteYAR;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;

/**
 * @mixin EmployerEliteYAR
 */
class EliteYARRelationResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->pivot->{EmployerEliteYAR::STATUS};

        $statusLabel = match ($status) {
            EmployerEliteYAR::STATUS_PENDING => 'در انتظار تایید',
            EmployerEliteYAR::STATUS_APPROVED => 'تایید شده',
            EmployerEliteYAR::STATUS_REJECTED => 'رد شده',
            default => $status,
        };

        // وضعیت تأیید مدارک الیت یار — از پروفایل مهندس یا پیمانکار
        $isVerified = $this->getEliteYARVerifiedStatus();

        return [
            'user_id' => $this->id,
            'name' => $this->whenLoaded('profile', $this->profile->{UserProfile::NAME} ?? ''),
            'family' => $this->whenLoaded('profile', $this->profile->{UserProfile::FAMILY} ?? ''),

            // موبایل ماسک می‌شود و کد ملی اصلاً برنمی‌گردد.
            //
            // این رابطه را هر طرف می‌تواند یک‌طرفه بسازد (دعوت، بدون نیاز به
            // پذیرش) و همین Resource برای فهرستِ دعوت‌های *در انتظار* هم استفاده
            // می‌شود — یعنی کد ملیِ کاملِ یک غریبه با یک دعوتِ پذیرفته‌نشده
            // برمی‌گشت. `UserResource` و `listRegisteredClients` در همین کنترلر
            // هر دو ماسک می‌کنند؛ فقط این مسیر جا مانده بود.
            User::MOBILE => $this->maskMobile($this->{User::MOBILE}),

            // status_info — ساختار یکپارچه با بقیه Resourceها
            'status_info' => [
                'key' => $status,
                'label' => $statusLabel,
                'tone' => StatusTone::fromStatus($status)->value,
            ],
            // حفظ فیلدهای قدیمی برای سازگاری عقبگرد
            'status' => $statusLabel,
            'status_code' => $status,

            'reject_reason' => $this->when(
                $status === EmployerEliteYAR::STATUS_REJECTED,
                $this->pivot->{EmployerEliteYAR::REJECT_REASON}
            ),

            // فلگ تأیید مدارک — فرانت از این برای نمایش badge «تأییدشده» استفاده می‌کند
            'is_verified' => $isVerified,
        ];
    }

    /**
     * تأییدِ الیت یار — همان محمولِ یگانه‌ی مدل (`User::isVerifiedEliteYAR()`).
     *
     * نسخه‌ی قبلی نامِ relationهای ناموجود (`engineerProfile`/`contractorProfile`)
     * را چک می‌کرد، پس همیشه به کوئریِ per-row می‌افتاد و سازه ۹۰ را هم
     * نمی‌شمرد. کنترلر حالا `engineer`/`contractor` را eager-load می‌کند.
     */
    private function getEliteYARVerifiedStatus(): bool
    {
        return $this->resource->isVerifiedEliteYAR();
    }

    /** هم‌الگو با `UserResource` و `EliteYARRelationController::listRegisteredClients`. */
    private function maskMobile(?string $mobile): ?string
    {
        if ($mobile === null || strlen($mobile) < 7) {
            return $mobile;
        }

        return substr($mobile, 0, 4).'****'.substr($mobile, -3);
    }
}
