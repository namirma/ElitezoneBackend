<?php

namespace App\Http\Resources\Profile;

use App\Enums\Shared\StatusTone;
use App\Models\ContractorProfile;
use App\Models\EmployerEliteYAR;
use App\Models\EngineerProfile;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

/**
 * @mixin \App\Models\EmployerEliteYAR
 */
class EliteYARRelationResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->pivot->{EmployerEliteYAR::STATUS};

        $statusLabel = match ($status) {
            EmployerEliteYAR::STATUS_PENDING  => 'در انتظار تایید',
            EmployerEliteYAR::STATUS_APPROVED => 'تایید شده',
            EmployerEliteYAR::STATUS_REJECTED => 'رد شده',
            default                              => $status,
        };

        // وضعیت تأیید مدارک الیت یار — از پروفایل مهندس یا پیمانکار
        $isVerified = $this->getEliteYARVerifiedStatus();

        return [
            'user_id' => $this->id,
            'name'    => $this->whenLoaded('profile', $this->profile->{UserProfile::NAME} ?? ''),
            'family'  => $this->whenLoaded('profile', $this->profile->{UserProfile::FAMILY} ?? ''),

            User::MOBILE       => $this->{User::MOBILE},
            User::NATIONAL_CODE => $this->{User::NATIONAL_CODE},

            // status_info — ساختار یکپارچه با بقیه Resourceها
            'status_info' => [
                'key'   => $status,
                'label' => $statusLabel,
                'tone'  => StatusTone::fromStatus($status)->value,
            ],
            // حفظ فیلدهای قدیمی برای سازگاری عقبگرد
            'status'      => $statusLabel,
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
}
