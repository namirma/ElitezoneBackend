<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\InviteConsultantRequest;
use App\Http\Requests\RejectInvitationRequest;
use App\Http\Resources\ConsultantRelationResource;
use App\Models\EmployerConsultant;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserType;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->wherePivot(EmployerConsultant::STATUS, EmployerConsultant::STATUS_APPROVED)
            ->with('profile')
            ->get();

        return success(ConsultantRelationResource::collection($employers), __('consultant.employers_list_received'));
    }

    public function inviteConsultant(InviteConsultantRequest $request): JsonResponse
    {
        $employer = Auth::user();
        $data = $request->validated();

        $consultant = $this->findConsultantByCredentials($data);
        if (!$consultant) {
            return error(__('auth.consultant_not_found'), [], 404);
        }

        $consultantTypeId = $consultant->{User::TYPE_ID};
        $expertTypes = UserType::whereIn(UserType::SLUG, ['engineer', 'contractor', 'expert'])->pluck(UserType::ID);

        if (!$expertTypes->contains($consultantTypeId)) {
            return error(__('auth.consultant_is_not_expert'), [], 422);
        }

        if ($employer->id === $consultant->id) {
            return error(__('auth.cannot_invite_self'), [], 422);
        }

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
                    EmployerConsultant::STATUS => EmployerConsultant::STATUS_PENDING,
                    EmployerConsultant::REJECT_REASON => null,
                    'updated_at' => now()
                ]);
            }
        } else {
            $employer->technicalConsultants()->attach($consultant->id, [
                EmployerConsultant::STATUS => EmployerConsultant::STATUS_PENDING
            ]);
        }

        $employer->loadMissing('profile');
        $employerName = $employer->profile->name ?? $employer->{User::MOBILE};
        $smsMessage = __('sms.consultant_invitation', ['employer_name' => $employerName]);

        UserNotification::create([
            UserNotification::USER_ID => $consultant->id,
            UserNotification::SENDER_ID => $employer->id,
            UserNotification::SUBJECT => __('global.consultantInvitationSubject'),
            UserNotification::BODY    => $smsMessage,
        ]);

        (new SmsService())->send($consultant->{User::MOBILE}, $smsMessage);

        return success([], __('consultant.invitation_sent'));
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

        if (!$pivot || $pivot->pivot->{EmployerConsultant::STATUS} !== EmployerConsultant::STATUS_PENDING) {
            return error(__('auth.invitation_not_found'), [], 404);
        }

        $consultant->associatedEmployers()->updateExistingPivot($employer->id, [
            EmployerConsultant::STATUS => EmployerConsultant::STATUS_APPROVED
        ]);

        return success([], __('consultant.invitation_accepted'));
    }

    public function rejectInvitation(RejectInvitationRequest $request, User $employer): JsonResponse
    {
        $consultant = Auth::user();
        $pivot = $consultant->associatedEmployers()->where(EmployerConsultant::EMPLOYER_ID, $employer->id)->first();

        if (!$pivot || $pivot->pivot->{EmployerConsultant::STATUS} !== EmployerConsultant::STATUS_PENDING) {
            return error(__('auth.invitation_not_found'), [], 404);
        }

        $consultant->associatedEmployers()->updateExistingPivot($employer->id, [
            EmployerConsultant::STATUS => EmployerConsultant::STATUS_REJECTED,
            EmployerConsultant::REJECT_REASON => $request->validated('reject_reason')
        ]);

        return success([], __('consultant.invitation_rejected'));
    }

    public function getRejectionReasons(): JsonResponse
    {
        return success(__('consultant.rejection_reasons'), __('consultant.rejection_reasons_received'));
    }

    private function findConsultantByCredentials(array $data): ?User
    {
        if (!empty($data[User::MOBILE])) {
            $mobile = $this->mobileTo09($data[User::MOBILE]);
            return User::where(User::MOBILE, $mobile)->first();
        }
        if (!empty($data[User::NATIONAL_CODE])) {
            return User::where(User::NATIONAL_CODE, $data[User::NATIONAL_CODE])->first();
        }
        return null;
    }

    private function mobileTo09(string $raw): string
    {
        $m = preg_replace('/\D/', '', $raw);
        if (str_starts_with($m, '0098')) $m = substr($m, 2);
        if (str_starts_with($m, '98'))   return '0' . substr($m, 2);
        if (str_starts_with($m, '9'))    return '0' . $m;
        return $m;
    }
}
