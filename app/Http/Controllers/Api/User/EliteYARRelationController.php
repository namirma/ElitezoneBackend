<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Profile\SyncUserBadgeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\InviteEliteYARRequest;
use App\Http\Requests\Profile\RejectInvitationRequest;
use App\Http\Resources\Profile\EliteYARRelationResource;
use App\Jobs\Notification\SendSmsJob;
use App\Models\EmployerEliteYAR;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserProfile;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EliteYARRelationController extends Controller
{
    public function listMyEliteYARs(): JsonResponse
    {
        $employer = Auth::user();
        $eliteYARs = $employer->technicalEliteYARs()
            ->with(['profile', 'engineer', 'contractor'])
            ->get();

        return success(EliteYARRelationResource::collection($eliteYARs), __('elite_yar.list_received'));
    }

    public function listMyEmployers(): JsonResponse
    {
        $eliteYAR = Auth::user();

        $employers = $eliteYAR->associatedEmployers()
            ->wherePivotIn(EmployerEliteYAR::STATUS, [
                EmployerEliteYAR::STATUS_APPROVED,
                EmployerEliteYAR::STATUS_REJECTED,
            ])
            ->with(['profile', 'engineer', 'contractor'])
            ->orderByPivot('updated_at', 'desc')
            ->get();

        return success(EliteYARRelationResource::collection($employers), __('elite_yar.employers_list_received'));
    }

    /**
     * دعوت الیت یار توسط کارفرما — فقط از طریق موبایل.
     *
     * سه خروجی مشخص (طبق مورد ۲، بند ۵):
     *
     *   ① not_registered   — موبایل در سیستم وجود ندارد
     *                        → فرانت پنجره «دعوت به ثبت‌نام» را نشان می‌دهد
     *
     *   ② elite_yar_not_verified — کاربر ثبت‌نام کرده ولی مدارک تأیید نشده
     *                        → خطای مناسب با پیشنهاد تماس با پشتیبانی
     *
     *   ③ (موفق)           — کاربر ثبت‌نام + تأییدشده → ادامه فرایند دعوت
     */
    public function inviteEliteYAR(InviteEliteYARRequest $request): JsonResponse
    {
        $employer = Auth::user();
        $mobile = PhoneNumber::to09($request->validated(User::MOBILE));

        // ── خروجی ① : not_registered ────────────────────────────────────────
        $eliteYAR = User::where(User::MOBILE, $mobile)->first();
        if (! $eliteYAR) {
            return success(
                ['outcome' => 'not_registered', 'mobile' => $mobile],
                __('elite_yar.not_registered_mobile')
            );
        }

        // دعوت‌شونده باید واقعاً الیت یار باشد. نقشِ `elite_yar` لحظه‌ی انتخابِ نقش
        // وصل می‌شود، پس این چک برای الیت یارِ تازه هم درست جواب می‌دهد — قبلاً
        // ترکیبِ سه اسلاگِ user_type خوانده می‌شد چون نقشِ فنی هنوز وصل نشده بود.
        if (! $eliteYAR->hasRole('elite_yar')) {
            return error(__('auth.elite_yar_is_not_technical'), [], 422);
        }

        if ($employer->id === $eliteYAR->id) {
            return error(__('auth.cannot_invite_self'), [], 422);
        }

        // ── خروجی ② : registered ولی not_verified ──────────────────────────
        // عضویتِ سازه ۹۰ فقط با خواندنِ پروفایلِ خودِ الیت یار تازه می‌شود؛ دعوت
        // یک نگاهِ تازه می‌اندازد (کش ۳۰ دقیقه، قطعی = آخرین وضعیت) تا گیت روی
        // پرچمی که هفته‌ها پیش نوشته شده تصمیم نگیرد.
        app(SyncUserBadgeAction::class)->execute($eliteYAR);
        $eliteYAR->refresh();

        if (! $this->isEliteYARVerified($eliteYAR)) {
            return error(__('elite_yar.elite_yar_not_verified'), [], 422);
        }

        // ── خروجی ③ : registered + verified → ادامه دعوت ───────────────────
        $existingRelation = $employer->technicalEliteYARs()
            ->where(EmployerEliteYAR::ELITE_YAR_ID, $eliteYAR->id)
            ->first();

        if ($existingRelation) {
            $status = $existingRelation->pivot->{EmployerEliteYAR::STATUS};
            $updatedAt = $existingRelation->pivot->updated_at;

            if (in_array($status, [EmployerEliteYAR::STATUS_PENDING, EmployerEliteYAR::STATUS_APPROVED])) {
                return error(__('auth.elite_yar_already_added'), [], 409);
            }

            if ($status === EmployerEliteYAR::STATUS_REJECTED) {
                if ($updatedAt->diffInHours(now()) < 24) {
                    // Carbon 3 اعشاری برمی‌گرداند («22.4999 ساعت» در پیام). رو به بالا تا
                    // «۰ ساعت» هم هرگز نشان داده نشود.
                    $hoursLeft = (int) ceil(24 - $updatedAt->diffInHours(now()));

                    return error(__('elite_yar.retry_delay', ['hours' => $hoursLeft]), [], 429);
                }

                $employer->technicalEliteYARs()->updateExistingPivot($eliteYAR->id, [
                    EmployerEliteYAR::STATUS => EmployerEliteYAR::STATUS_PENDING,
                    EmployerEliteYAR::REJECT_REASON => null,
                    'updated_at' => now(),
                ]);
            }
        } else {
            $employer->technicalEliteYARs()->attach($eliteYAR->id, [
                EmployerEliteYAR::STATUS => EmployerEliteYAR::STATUS_PENDING,
            ]);
        }

        $employer->loadMissing('profile');
        $employerName = $employer->profile->name ?? $employer->{User::MOBILE};
        $params = ['employer_name' => $employerName];

        UserNotification::create([
            UserNotification::USER_ID => $eliteYAR->id,
            UserNotification::SENDER_ID => $employer->id,
            UserNotification::SUBJECT => __('global.eliteYARInvitationSubject'),
            UserNotification::BODY => SmsTemplate::text('elite_yar_invitation', $params),
        ]);

        SendSmsJob::template($eliteYAR->{User::MOBILE}, 'elite_yar_invitation', $params);

        return success(
            ['outcome' => 'invited'],
            __('elite_yar.invitation_sent')
        );
    }

    public function removeEliteYAR(User $eliteYAR): JsonResponse
    {
        $employer = Auth::user();

        if ($employer->technicalEliteYARs()->detach($eliteYAR->id)) {
            return success([], __('auth.elite_yar_relation_removed'));
        }

        return error(__('auth.elite_yar_relation_not_found'), [], 404);
    }

    public function listMyInvitations(): JsonResponse
    {
        $eliteYAR = Auth::user();
        $invitations = $eliteYAR->associatedEmployers()
            ->where(EmployerEliteYAR::STATUS, EmployerEliteYAR::STATUS_PENDING)
            ->with(['profile', 'engineer', 'contractor'])
            ->get();

        return success(EliteYARRelationResource::collection($invitations), __('elite_yar.invitations_list_received'));
    }

    public function acceptInvitation(User $employer): JsonResponse
    {
        $eliteYAR = Auth::user();
        $pivot = $eliteYAR->associatedEmployers()->where(EmployerEliteYAR::EMPLOYER_ID, $employer->id)->first();

        if (! $pivot || $pivot->pivot->{EmployerEliteYAR::STATUS} !== EmployerEliteYAR::STATUS_PENDING) {
            return error(__('auth.invitation_not_found'), [], 404);
        }

        $eliteYAR->associatedEmployers()->updateExistingPivot($employer->id, [
            EmployerEliteYAR::STATUS => EmployerEliteYAR::STATUS_APPROVED,
        ]);

        return success([], __('elite_yar.invitation_accepted'));
    }

    public function rejectInvitation(RejectInvitationRequest $request, User $employer): JsonResponse
    {
        $eliteYAR = Auth::user();
        $pivot = $eliteYAR->associatedEmployers()->where(EmployerEliteYAR::EMPLOYER_ID, $employer->id)->first();

        if (! $pivot || $pivot->pivot->{EmployerEliteYAR::STATUS} !== EmployerEliteYAR::STATUS_PENDING) {
            return error(__('auth.invitation_not_found'), [], 404);
        }

        $eliteYAR->associatedEmployers()->updateExistingPivot($employer->id, [
            EmployerEliteYAR::STATUS => EmployerEliteYAR::STATUS_REJECTED,
            EmployerEliteYAR::REJECT_REASON => $request->validated('reject_reason'),
        ]);

        return success([], __('elite_yar.invitation_rejected'));
    }

    public function getRejectionReasons(): JsonResponse
    {
        // ‏`trans()` و نه `__()`: مقدار آرایه است و Scramble هر `__()` را رشته فرض
        // می‌کند و روی آرایه کلِ `scramble:export` را می‌خواباند (SuccessResponseExtension).
        return success(trans('elite_yar.rejection_reasons'), __('elite_yar.rejection_reasons_received'));
    }

    /**
     * لیست مشتریانی که این الیت یار از طریق جریان «خرید برای دیگری» احراز هویت کرده.
     */
    public function listRegisteredClients(): JsonResponse
    {
        $eliteYAR = Auth::user();

        $clients = $eliteYAR->registeredClients()
            ->with('profile')
            ->orderByPivot('created_at', 'desc')
            ->get()
            ->map(function (User $client) {
                $mobile = (string) $client->{User::MOBILE};

                return [
                    'user_id' => $client->id,
                    'name' => $client->profile?->{UserProfile::NAME},
                    'family' => $client->profile?->{UserProfile::FAMILY},
                    'national_code' => $client->{User::NATIONAL_CODE},
                    'mobile' => $this->maskMobile($mobile),
                    'email' => $client->profile?->email ?? null,
                    'created_at' => $client->pivot->created_at?->toDateTimeString(),
                ];
            });

        return success($clients, __('elite_yar.registered_clients_received'));
    }

    private function maskMobile(?string $mobile): ?string
    {
        if ($mobile === null || strlen($mobile) < 7) {
            return $mobile;
        }

        return substr($mobile, 0, 4).str_repeat('*', strlen($mobile) - 7).substr($mobile, -3);
    }

    /**
     * تأییدِ الیت یار — همان محمولِ یگانه‌ی مدل. قبلاً کپیِ نسخه‌ی
     * `StoreOrderRequest` بود که سازه ۹۰ را نمی‌شمرد و دعوتِ عضوِ سازه ۹۰
     * را ۴۲۲ می‌کرد، در حالی که پروفایلش «تأییدشده» نشان می‌داد.
     */
    private function isEliteYARVerified(User $eliteYAR): bool
    {
        return $eliteYAR->isVerifiedEliteYAR();
    }
}
