<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\SendEliteYARSmsInviteRequest;
use App\Jobs\Notification\SendInviteSmsJob;
use App\Models\EliteYARSmsInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * دعوتِ پیامکیِ الیت یارِ ثبت‌نام‌نکرده، توسط کارفرما.
 *
 * نیمه‌ی دومِ جریانی که تا امروز ناتمام بود: کارفرما شماره‌ای وارد می‌کند،
 * `EliteYARRelationController::inviteEliteYAR` پاسخ `not_registered` می‌دهد،
 * فرانت اورلیِ «دعوت با پیامک» را باز می‌کند — و اندپوینتش وجود نداشت.
 *
 * قرینه‌ی دقیقِ `EmployerInviteController` است (که الیت یار کارفرما را دعوت
 * می‌کند): همان سقف، همان قواعد، همان قرارداد پاسخ.
 */
class EliteYARInviteController extends Controller
{
    /**
     * POST /api/profile/elite-yar-invites/sms
     */
    public function sendSms(SendEliteYARSmsInviteRequest $request): JsonResponse
    {
        $employer = Auth::user();
        $used = EliteYARSmsInvite::where(EliteYARSmsInvite::EMPLOYER_ID, $employer->id)->count();

        if ($used >= EliteYARSmsInvite::MAX_INVITES) {
            return error(__('profile.invite_cap_reached'), [], 422);
        }

        // شماره در `prepareForValidation` نرمال شده، پس همان مقدارِ اعتبارسنجی‌شده
        // ذخیره می‌شود و `users.mobile` هم با همین شکل نگهداری می‌شود.
        $mobile = $request->validated('mobile');

        $invite = EliteYARSmsInvite::create([
            EliteYARSmsInvite::EMPLOYER_ID => $employer->id,
            EliteYARSmsInvite::MOBILE => $mobile,
            EliteYARSmsInvite::SENT_AT => now(),
        ]);

        SendInviteSmsJob::dispatch(
            $invite->id,
            $mobile,
            __('sms.elite_yar_sms_invitation', [
                'employer_name' => $employer->full_name,
                'link' => config('app.front_url'),
            ]),
            EliteYARSmsInvite::class,
        );

        return success(
            ['remaining_invites' => max(0, EliteYARSmsInvite::MAX_INVITES - ($used + 1))],
            __('profile.invite_sent_successfully')
        );
    }

    /**
     * GET /api/profile/elite-yar-invites/remaining
     */
    public function remaining(): JsonResponse
    {
        $used = EliteYARSmsInvite::where(EliteYARSmsInvite::EMPLOYER_ID, Auth::id())->count();

        return success(
            ['remaining_invites' => max(0, EliteYARSmsInvite::MAX_INVITES - $used)],
            __('profile.remaining_invites')
        );
    }
}
