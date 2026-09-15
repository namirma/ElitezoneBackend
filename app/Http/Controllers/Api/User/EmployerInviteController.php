<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\SendEmployerSmsInviteRequest;
use App\Jobs\Notification\SendInviteSmsJob;
use App\Models\EmployerSmsInvite;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EmployerInviteController extends Controller
{
    /**
     * ارسال دعوت‌نامه SMS برای کارفرما توسط الیت یار.
     *
     * POST /api/profile/employer-invites/sms
     */
    public function send(SendEmployerSmsInviteRequest $request): JsonResponse
    {
        $advisor = Auth::user();
        $used = EmployerSmsInvite::where(EmployerSmsInvite::ADVISOR_ID, $advisor->id)->count();

        if ($used >= EmployerSmsInvite::MAX_INVITES) {
            return error(__('profile.invite_cap_reached'), [], 422);
        }

        $mobile = PhoneNumber::to09($request->validated('mobile'));

        $invite = EmployerSmsInvite::create([
            EmployerSmsInvite::ADVISOR_ID => $advisor->id,
            EmployerSmsInvite::MOBILE => $mobile,
            EmployerSmsInvite::SENT_AT => now(),
        ]);

        SendInviteSmsJob::dispatch($invite->id, $mobile, '', EmployerSmsInvite::class, 'employer_invitation', [
            'advisor_name' => $advisor->full_name,
            'link' => config('app.front_url'),
        ]);

        $remaining = max(0, EmployerSmsInvite::MAX_INVITES - ($used + 1));

        return success(
            ['remaining_invites' => $remaining],
            __('profile.invite_sent_successfully')
        );
    }

    /**
     * تعداد دعوت‌های باقی‌مانده الیت یار.
     *
     * GET /api/profile/employer-invites/remaining
     */
    public function remaining(): JsonResponse
    {
        $used = EmployerSmsInvite::where(EmployerSmsInvite::ADVISOR_ID, Auth::id())->count();
        $remaining = max(0, EmployerSmsInvite::MAX_INVITES - $used);

        return success(
            ['remaining_invites' => $remaining],
            __('profile.remaining_invites')
        );
    }
}
