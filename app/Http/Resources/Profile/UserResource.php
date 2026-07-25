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

        // موبایل/وضعیت فعال‌بودن فقط برای خود شخص و ادمین کامل برمی‌گردد — در نمایش
        // به دیگران (مثل تأمین‌کننده در آیتم سفارش) mask/مخفی می‌شود.
        $viewer = $request->user();
        $viewerIsAdmin = (bool) $viewer?->hasRole('admin');
        $canSeeMobile = $viewer && ((int) $viewer->id === (int) $this->{User::ID} || $viewerIsAdmin);

        return [
            User::ID => $this->{User::ID},
            User::TYPE_ID => $this->{User::TYPE_ID},
            User::MOBILE => $canSeeMobile
                ? $this->{User::MOBILE}
                : $this->maskMobile($this->{User::MOBILE}),
            // بک‌آفیس (لیست/جزئیات کاربران) به این فیلد نیاز دارد؛ قبلاً اصلاً برنمی‌گشت.
            User::IS_ACTIVE => $canSeeMobile ? (bool) $this->{User::IS_ACTIVE} : null,
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
            // مجوزهای سطح‌بندیِ پنل ادمین — فقط برای خودِ ادمین روی پروفایلِ خودش (هرگز
            // برای ادمینی که پروفایل ادمین دیگری را می‌بیند — AdminManagerController مسیر
            // اختصاصی خودش را دارد). بدون این، از وقتی EnsureAdminHasPermission به
            // fail-closed تغییر کرد، پنل هیچ راهی نداشت بفهمد کدام تب را نشان دهد جز
            // اینکه endpointها را صدا بزند و ۴۰۳ بگیرد.
            $this->mergeWhen($this->shouldExposePermissions($viewer), fn () => [
                'permissions' => $this->permissions->pluck('name')->values(),
            ]),
            User::RELATION_BADGE => $selectedBadge ? new BadgeResource($selectedBadge) : null,
            User::RELATION_EDUIX_RECORDS => EduixRecordResource::collection($this->whenLoaded(User::RELATION_EDUIX_RECORDS)),
            User::RELATION_JOB_EXPERIENCES => JobExperienceResource::collection($this->whenLoaded(User::RELATION_JOB_EXPERIENCES)),
            // یادداشت‌های داخلیِ ادمین — فقط برای ادمین (بک‌آفیس). لیستِ کاربران
            // withCount می‌کند (فقط تعداد، برای ستون جدول) و صفحه‌ی جزئیات
            // relation را لود می‌کند (متن کامل). برای کاربر عادی هیچ‌کدام ظاهر
            // نمی‌شود، چون نه eager-load می‌شوند نه withCount.
            'notes_count' => $this->when(
                $viewerIsAdmin && $this->notes_count !== null,
                fn () => (int) $this->notes_count
            ),
            'notes' => $this->when(
                $viewerIsAdmin,
                fn () => UserNoteResource::collection($this->whenLoaded('notes'))
            ),
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,
            'is_verified' => $this->getIsVerified(),
        ];
    }

    /**
     * مجوزها فقط وقتی برگردانده می‌شوند که بیننده، خودِ همان ادمین باشد.
     *
     * مجوزهای یک ادمینِ دیگر اطلاعاتِ ساختارِ دسترسیِ داخلی است و روی
     * endpointهای عمومیِ پروفایل جایی ندارد؛ برای مدیریتِ ادمین‌ها،
     * AdminManagerController مسیر اختصاصی خودش را دارد.
     */
    private function shouldExposePermissions(?User $viewer): bool
    {
        return $viewer !== null
            && (int) $viewer->id === (int) $this->{User::ID}
            && $viewer->hasRole('admin');
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
