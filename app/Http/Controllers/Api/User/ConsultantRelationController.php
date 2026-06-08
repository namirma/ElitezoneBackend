<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;

use App\Http\Requests\Profile\InviteConsultantRequest;
use App\Http\Requests\Profile\RejectInvitationRequest;
use App\Http\Resources\Profile\ConsultantRelationResource;
use App\Jobs\Notification\SendSmsJob;
use App\Models\ContractorProfile;
use App\Models\EmployerConsultant;
use App\Models\EngineerProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserType;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ConsultantRelationController extends Controller
{
    public function listMyConsultants(): JsonResponse
    {
        $employer = Auth::user();
        $consultants = $employer->technicalConsultants()
            ->with('profile')
            ->get();

        return success(ConsultantRelationResource::collection($consultants), __('consultant.list_received'));
    }

    public function listMyEmployers(): JsonResponse
    {
        $consultant = Auth::user();

        $employers = $consultant->associatedEmployers()
            ->wherePivotIn(EmployerConsultant::STATUS, [
                EmployerConsultant::STATUS_APPROVED,
                EmployerConsultant::STATUS_REJECTED,
            ])
            ->with('profile')
            ->orderByPivot('updated_at', 'desc')
            ->get();

        return success(ConsultantRelationResource::collection($employers), __('consultant.employers_list_received'));
    }

    /**
     * دعوت مشاور فنی توسط کارفرما — فقط از طریق موبایل.
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
    public function inviteConsultant(InviteConsultantRequest $request): JsonResponse
    {
        $employer = Auth::user();
        $mobile = PhoneNumber::to09($request->validated(User::MOBILE));

        // ── خروجی ① : not_registered ────────────────────────────────────────
        $consultant = User::where(User::MOBILE, $mobile)->first();
        if (! $consultant) {
            return success(
                ['outcome' => 'not_registered', 'mobile' => $mobile],
                __('consultant.not_registered_mobile')
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
        if (! $this->isConsultantVerified($consultant)) {
            return error(__('consultant.consultant_not_verified'), [], 422);
        }

        // ── خروجی ③ : registered + verified → ادامه دعوت ───────────────────
        $existingRelation = $employer->technicalConsultants()
            ->where(EmployerConsultant::CONSULTANT_ID, $consultant->id)
            ->first();

        if ($existingRelation) {
            $status = $existingRelation->pivot->{EmployerConsultant::STATUS};
            $updatedAt = $existingRelation->pivot->updated_at;

            if (in_array($status, [EmployerConsultant::STATUS_PENDING, EmployerConsultant::STATUS_APPROVED])) {
                return error(__('auth.consultant_already_added'), [], 409);
            }

            if ($status === EmployerConsultant::STATUS_REJECTED) {
                if ($updatedAt->diffInHours(now()) < 24) {
                    $hoursLeft = 24 - $updatedAt->diffInHours(now());

                    return error(__('consultant.retry_delay', ['hours' => $hoursLeft]), [], 429);
                }

                $employer->technicalConsultants()->updateExistingPivot($consultant->id, [
                    EmployerConsultant::STATUS       => EmployerConsultant::STATUS_PENDING,
                    EmployerConsultant::REJECT_REASON => null,
                    'updated_at'                     => now(),
                ]);
            }
        } else {
            $employer->technicalConsultants()->attach($consultant->id, [
                EmployerConsultant::STATUS => EmployerConsultant::STATUS_PENDING,
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
            __('consultant.invitation_sent')
        );
    }

    public function removeConsultant(User $consultant): JsonResponse
    {
        $employer = Auth::user();

        if ($employer->technicalConsultants()->detach($consultant->id)) {
            return success([], __('auth.consultant_relation_removed'));
        }

        return error(__('auth.consultant_relation_not_found'), [], 404);
    }

    public function listMyInvitations(): JsonResponse
    {
        $consultant = Auth::user();
        $invitations = $consultant->associatedEmployers()
            ->where(EmployerConsultant::STATUS, EmployerConsultant::STATUS_PENDING)
            ->with('profile')
            ->get();

        return success(ConsultantRelationResource::collection($invitations), __('consultant.invitations_list_received'));
    }

    public function acceptInvitation(User $employer): JsonResponse
    {
        $consultant = Auth::user();
        $pivot = $consultant->associatedEmployers()->where(EmployerConsultant::EMPLOYER_ID, $employer->id)->first();

        if (! $pivot || $pivot->pivot->{EmployerConsultant::STATUS} !== EmployerConsultant::STATUS_PENDING) {
            return error(__('auth.invitation_not_found'), [], 404);
        }

        $consultant->associatedEmployers()->updateExistingPivot($employer->id, [
            EmployerConsultant::STATUS => EmployerConsultant::STATUS_APPROVED,
        ]);

        return success([], __('consultant.invitation_accepted'));
    }

    public function rejectInvitation(RejectInvitationRequest $request, User $employer): JsonResponse
    {
        $consultant = Auth::user();
        $pivot = $consultant->associatedEmployers()->where(EmployerConsultant::EMPLOYER_ID, $employer->id)->first();

        if (! $pivot || $pivot->pivot->{EmployerConsultant::STATUS} !== EmployerConsultant::STATUS_PENDING) {
            return error(__('auth.invitation_not_found'), [], 404);
        }

        $consultant->associatedEmployers()->updateExistingPivot($employer->id, [
            EmployerConsultant::STATUS => EmployerConsultant::STATUS_REJECTED,
            EmployerConsultant::REJECT_REASON => $request->validated('reject_reason'),
        ]);

        return success([], __('consultant.invitation_rejected'));
    }

    public function getRejectionReasons(): JsonResponse
    {
        return success(__('consultant.rejection_reasons'), __('consultant.rejection_reasons_received'));
    }

    /**
     * بررسی تأیید مدارک مشاور از روی پروفایل مهندس یا پیمانکار.
     */
    private function isConsultantVerified(User $consultant): bool
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
