<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;

use App\Http\Requests\Profile\InviteEliteYarRequest;
use App\Http\Requests\Profile\RejectInvitationRequest;
use App\Http\Resources\Profile\EliteYarRelationResource;
use App\Jobs\Notification\SendSmsJob;
use App\Models\ContractorProfile;
use App\Models\EmployerEliteYar;
use App\Models\EngineerProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserProfile;
use App\Models\UserType;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EliteYarRelationController extends Controller
{
    public function listMyEliteYars(): JsonResponse
    {
        $employer = Auth::user();
        $consultants = $employer->technicalEliteYars()
            ->with('profile')
            ->get();

        return success(EliteYarRelationResource::collection($consultants), __('elite_yar.list_received'));
    }

    public function listMyEmployers(): JsonResponse
    {
        $consultant = Auth::user();

        $employers = $consultant->associatedEmployers()
            ->wherePivotIn(EmployerEliteYar::STATUS, [
                EmployerEliteYar::STATUS_APPROVED,
                EmployerEliteYar::STATUS_REJECTED,
            ])
            ->with('profile')
            ->orderByPivot('updated_at', 'desc')
            ->get();

        return success(EliteYarRelationResource::collection($employers), __('elite_yar.employers_list_received'));
    }

    /**
     * دعوت الیت یار توسط کارفرما — فقط از طریق موبایل.
     *
     * سه خروجی مشخص (طبق مورد ۲، بند ۵):
     *
     *   ① not_registered   — موبایل در سیستم وجود ندارد
     *                        → فرانت پنجره «دعوت به ثبت‌نام» را نشان می‌دهد
     *
     *   ② consultant_not_verified — کاربر ثبت‌نام کرده ولی مدارک تأیید نشده
     *                        → خطای مناسب با پیشنهاد تماس با پشتیبانی
     *
     *   ③ (موفق)           — کاربر ثبت‌نام + تأییدشده → ادامه فرایند دعوت
     */
    public function inviteEliteYar(InviteEliteYarRequest $request): JsonResponse
    {
        $employer = Auth::user();
        $mobile = PhoneNumber::to09($request->validated(User::MOBILE));

        // ── خروجی ① : not_registered ────────────────────────────────────────
        $consultant = User::where(User::MOBILE, $mobile)->first();
        if (! $consultant) {
            return success(
                ['outcome' => 'not_registered', 'mobile' => $mobile],
                __('elite_yar.not_registered_mobile')
            );
        }

        // بررسی نوع کاربر — باید نقش فنی (engineer/contractor/expert) داشته باشد
        $consultantTypeId = $consultant->{User::TYPE_ID};
        $expertTypes = UserType::whereIn(UserType::SLUG, ['engineer', 'contractor', 'expert'])
            ->pluck(UserType::ID);

        if (! $expertTypes->contains($consultantTypeId)) {
            return error(__('auth.consultant_is_not_expert'), [], 422);
        }

        if ($employer->id === $consultant->id) {
            return error(__('auth.cannot_invite_self'), [], 422);
        }

        // ── خروجی ② : registered ولی not_verified ──────────────────────────
        if (! $this->isEliteYarVerified($consultant)) {
            return error(__('elite_yar.consultant_not_verified'), [], 422);
        }

        // ── خروجی ③ : registered + verified → ادامه دعوت ───────────────────
        $existingRelation = $employer->technicalEliteYars()
            ->where(EmployerEliteYar::CONSULTANT_ID, $consultant->id)
            ->first();

        if ($existingRelation) {
            $status = $existingRelation->pivot->{EmployerEliteYar::STATUS};
            $updatedAt = $existingRelation->pivot->updated_at;

            if (in_array($status, [EmployerEliteYar::STATUS_PENDING, EmployerEliteYar::STATUS_APPROVED])) {
                return error(__('auth.consultant_already_added'), [], 409);
            }

            if ($status === EmployerEliteYar::STATUS_REJECTED) {
                if ($updatedAt->diffInHours(now()) < 24) {
                    $hoursLeft = 24 - $updatedAt->diffInHours(now());

                    return error(__('elite_yar.retry_delay', ['hours' => $hoursLeft]), [], 429);
                }

                $employer->technicalEliteYars()->updateExistingPivot($consultant->id, [
                    EmployerEliteYar::STATUS       => EmployerEliteYar::STATUS_PENDING,
                    EmployerEliteYar::REJECT_REASON => null,
                    'updated_at'                     => now(),
                ]);
            }
        } else {
            $employer->technicalEliteYars()->attach($consultant->id, [
                EmployerEliteYar::STATUS => EmployerEliteYar::STATUS_PENDING,
            ]);
        }

        $employer->loadMissing('profile');
        $employerName = $employer->profile->name ?? $employer->{User::MOBILE};
        $smsMessage = __('sms.consultant_invitation', ['employer_name' => $employerName]);

        UserNotification::create([
            UserNotification::USER_ID  => $consultant->id,
            UserNotification::SENDER_ID => $employer->id,
            UserNotification::SUBJECT  => __('global.consultantInvitationSubject'),
            UserNotification::BODY     => $smsMessage,
        ]);

        SendSmsJob::dispatch($consultant->{User::MOBILE}, $smsMessage);

        return success(
            ['outcome' => 'invited'],
            __('elite_yar.invitation_sent')
        );
    }

    public function removeEliteYar(User $consultant): JsonResponse
    {
        $employer = Auth::user();

        if ($employer->technicalEliteYars()->detach($consultant->id)) {
            return success([], __('auth.consultant_relation_removed'));
        }

        return error(__('auth.consultant_relation_not_found'), [], 404);
    }

    public function listMyInvitations(): JsonResponse
    {
        $consultant = Auth::user();
        $invitations = $consultant->associatedEmployers()
            ->where(EmployerEliteYar::STATUS, EmployerEliteYar::STATUS_PENDING)
            ->with('profile')
            ->get();

        return success(EliteYarRelationResource::collection($invitations), __('elite_yar.invitations_list_received'));
    }

    public function acceptInvitation(User $employer): JsonResponse
    {
        $consultant = Auth::user();
        $pivot = $consultant->associatedEmployers()->where(EmployerEliteYar::EMPLOYER_ID, $employer->id)->first();

        if (! $pivot || $pivot->pivot->{EmployerEliteYar::STATUS} !== EmployerEliteYar::STATUS_PENDING) {
            return error(__('auth.invitation_not_found'), [], 404);
        }

        $consultant->associatedEmployers()->updateExistingPivot($employer->id, [
            EmployerEliteYar::STATUS => EmployerEliteYar::STATUS_APPROVED,
        ]);

        return success([], __('elite_yar.invitation_accepted'));
    }

    public function rejectInvitation(RejectInvitationRequest $request, User $employer): JsonResponse
    {
        $consultant = Auth::user();
        $pivot = $consultant->associatedEmployers()->where(EmployerEliteYar::EMPLOYER_ID, $employer->id)->first();

        if (! $pivot || $pivot->pivot->{EmployerEliteYar::STATUS} !== EmployerEliteYar::STATUS_PENDING) {
            return error(__('auth.invitation_not_found'), [], 404);
        }

        $consultant->associatedEmployers()->updateExistingPivot($employer->id, [
            EmployerEliteYar::STATUS => EmployerEliteYar::STATUS_REJECTED,
            EmployerEliteYar::REJECT_REASON => $request->validated('reject_reason'),
        ]);

        return success([], __('elite_yar.invitation_rejected'));
    }

    public function getRejectionReasons(): JsonResponse
    {
        return success(__('elite_yar.rejection_reasons'), __('elite_yar.rejection_reasons_received'));
    }

    /**
     * لیست مشتریانی که این الیت یار از طریق جریان «خرید برای دیگری» احراز هویت کرده.
     */
    public function listRegisteredClients(): JsonResponse
    {
        $consultant = Auth::user();

        $clients = $consultant->registeredClients()
            ->with('profile')
            ->orderByPivot('created_at', 'desc')
            ->get()
            ->map(function (User $client) {
                $mobile = (string) $client->{User::MOBILE};

                return [
                    'user_id'       => $client->id,
                    'name'          => $client->profile?->{UserProfile::NAME},
                    'family'        => $client->profile?->{UserProfile::FAMILY},
                    'national_code' => $client->{User::NATIONAL_CODE},
                    'mobile'        => $this->maskMobile($mobile),
                    'email'         => $client->profile?->email ?? null,
                    'created_at'    => $client->pivot->created_at?->toDateTimeString(),
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
     * بررسی تأیید مدارک الیت یار از روی پروفایل مهندس یا پیمانکار.
     */
    private function isEliteYarVerified(User $consultant): bool
    {
        $engineerProfile = EngineerProfile::where(EngineerProfile::USER_ID, $consultant->id)->first();
        if ($engineerProfile) {
            return (bool) $engineerProfile->{EngineerProfile::IS_VERIFIED};
        }

        $contractorProfile = ContractorProfile::where(ContractorProfile::USER_ID, $consultant->id)->first();
        if ($contractorProfile) {
            return (bool) $contractorProfile->{ContractorProfile::IS_VERIFIED};
        }

        return false;
    }
}
