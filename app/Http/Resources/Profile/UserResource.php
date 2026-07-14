<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\BaseJsonResource;
use App\Http\Resources\Supplier\SupplierProfileResource;
use App\Models\ContractorProfile;
use App\Models\EngineerProfile;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\User
 */
class UserResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $selectedBadge = null;
        if ($this->relationLoaded(User::RELATION_BADGE) && $this->{User::RELATION_BADGE}) {
            $selectedBadge = $this->{User::RELATION_BADGE};
        } elseif ($this->relationLoaded('badges') && $this->badges->isNotEmpty()) {
            $selectedBadge = $this->badges->last();
        }

        // موبایل فقط برای خود شخص و ادمین کامل برمی‌گردد — در نمایش به دیگران
        // (مثل تأمین‌کننده در آیتم سفارش) mask می‌شود.
        $viewer = $request->user();
        $canSeeMobile = $viewer && ((int) $viewer->id === (int) $this->{User::ID} || $viewer->hasRole('admin'));

        return [
            User::ID => $this->{User::ID},
            User::TYPE_ID => $this->{User::TYPE_ID},
            User::MOBILE => $canSeeMobile
                ? $this->{User::MOBILE}
                : $this->maskMobile($this->{User::MOBILE}),
            User::CREATED_AT => $this->{User::CREATED_AT},
            User::PASSWORD => (bool) $this->{User::PASSWORD},
            User::RELATION_PROFILE => new UserProfileResource($this->whenLoaded(User::RELATION_PROFILE)),
            User::RELATION_ENGINEER => new EngineerProfileResourse($this->whenLoaded(User::RELATION_ENGINEER)),
            User::RELATION_CONTRACTOR => new ContractorProfileResource($this->whenLoaded(User::RELATION_CONTRACTOR)),
            User::RELATION_SUPPLIER => new SupplierProfileResource($this->whenLoaded(User::RELATION_SUPPLIER)),
            User::RELATION_EMPLOYER => new EmployerProfileResource($this->whenLoaded(User::RELATION_EMPLOYER)),
            User::RELATION_TYPE => new UserTypeResource($this->whenLoaded(User::RELATION_TYPE)),
            User::RELATION_ROLES => $this->whenLoaded(User::RELATION_ROLES, function () {
                return $this->roles->pluck('name');
            }),
            User::RELATION_BADGE => $selectedBadge ? new BadgeResource($selectedBadge) : null,
            User::RELATION_EDUIX_RECORDS => EduixRecordResource::collection($this->whenLoaded(User::RELATION_EDUIX_RECORDS)),
            User::RELATION_JOB_EXPERIENCES => JobExperienceResource::collection($this->whenLoaded(User::RELATION_JOB_EXPERIENCES)),
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,
            'is_verified' => $this->getIsVerified(),
        ];
    }

    private function maskMobile(?string $mobile): ?string
    {
        if ($mobile === null || strlen($mobile) < 7) {
            return $mobile;
        }

        return substr($mobile, 0, 4).str_repeat('*', strlen($mobile) - 7).substr($mobile, -3);
    }

    /**
     * وضعیت تأیید مدارک کاربر — فقط برای «الیت یار» (مهندس یا پیمانکار).
     *
     * منطق:
     *   - پروفایل مهندس/پیمانکار لود شده و موجود است → مقدار واقعی is_verified
     *   - کاربر «الیت یار» است (type.slug = expert) ولی هنوز پروفایل تأییدشده‌ای
     *     ندارد (نقش را انتخاب کرده ولی مدرکی آپلود/تأیید نکرده) → false
     *     (تا فرانت بَج «تأیید نشده» + CTA آپلود مدارک را نشان دهد)
     *   - نقش‌های غیرالیت یار (کارفرما/تأمین‌کننده/مشتری) → null (بدون بج)
     *
     * نکته: نقشِ دقیقِ engineer/contractor فقط بعد از آپلود مدارک ست می‌شود؛
     * پس یک expertِ تازه ممکن است roles خالی و بدون پروفایل باشد — برای همین
     * تشخیصِ «الیت یار بودن» با type.slug انجام می‌شود نه صرفاً با وجود پروفایل.
     *
     * کنترلر از پیش engineerProfile / contractorProfile / type را لود کرده، N+1 نیست.
     */
    private function getIsVerified(): ?bool
    {
        if ($this->relationLoaded(User::RELATION_ENGINEER) && $this->{User::RELATION_ENGINEER}) {
            $eng = $this->{User::RELATION_ENGINEER};

            // saze90 membership auto-verifies, alongside the admin-reviewed docs flag.
            return (bool) ($eng->{EngineerProfile::IS_VERIFIED} || $eng->{EngineerProfile::IS_SAZE90_USER});
        }

        if ($this->relationLoaded(User::RELATION_CONTRACTOR) && $this->{User::RELATION_CONTRACTOR}) {
            return (bool) $this->{User::RELATION_CONTRACTOR}->{ContractorProfile::IS_VERIFIED};
        }

        // الیت یارِ تأییدنشده (هنوز مدرکی ندارد) → false، در غیر این صورت null
        if ($this->isExpertType()) {
            return false;
        }

        return null;
    }

    /**
     * آیا نوع کاربر «الیت یار» (expert) است؟ — از روی type.slug.
     * فقط وقتی relationِ type لود شده باشد قابل تشخیص است؛ در غیر این صورت false
     * (مثلاً در Resourceهایی که type را لود نمی‌کنند، بج نمایش داده نمی‌شود).
     */
    private function isExpertType(): bool
    {
        if ($this->relationLoaded(User::RELATION_TYPE) && $this->{User::RELATION_TYPE}) {
            return strtolower((string) $this->{User::RELATION_TYPE}->{UserType::SLUG}) === 'expert';
        }

        return false;
    }
}
