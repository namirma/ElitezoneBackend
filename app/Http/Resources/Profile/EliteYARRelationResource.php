<?php

namespace App\Http\Resources\Profile;

use App\Enums\Shared\StatusTone;
use App\Http\Resources\BaseJsonResource;
use App\Models\ContractorProfile;
use App\Models\EmployerEliteYAR;
use App\Models\EngineerProfile;
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
     * تشخیص وضعیت تأیید مدارک از روی پروفایل مهندس یا پیمانکار.
     *
     * الیت یار می‌تواند مهندس (engineer) یا پیمانکار (contractor) باشد؛
     * هر دو پروفایل دارای is_verified/verified_at هستند (migration 200100).
     */
    private function getEliteYARVerifiedStatus(): bool
    {
        // ترجیح: اگر پروفایل از پیش لود شده، از آن استفاده می‌کنیم
        if ($this->relationLoaded('engineerProfile') && $this->engineerProfile) {
            return (bool) $this->engineerProfile->{EngineerProfile::IS_VERIFIED};
        }

        if ($this->relationLoaded('contractorProfile') && $this->contractorProfile) {
            return (bool) $this->contractorProfile->{ContractorProfile::IS_VERIFIED};
        }

        // fallback: یک کوئری کوچک می‌زنیم (برای لیست‌های کوچک قابل قبول است)
        $engineerProfile = EngineerProfile::where(EngineerProfile::USER_ID, $this->id)->first();
        if ($engineerProfile) {
            return (bool) $engineerProfile->{EngineerProfile::IS_VERIFIED};
        }

        $contractorProfile = ContractorProfile::where(ContractorProfile::USER_ID, $this->id)->first();
        if ($contractorProfile) {
            return (bool) $contractorProfile->{ContractorProfile::IS_VERIFIED};
        }

        return false;
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
