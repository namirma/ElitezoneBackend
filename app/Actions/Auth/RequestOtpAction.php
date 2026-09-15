<?php

namespace App\Actions\Auth;

use App\Jobs\Notification\SendSmsJob;
use App\Models\User;
use App\Support\OtpSecret;
use Illuminate\Support\Carbon;

/**
 * Generates a fresh OTP, persists it on the User, and dispatches the SMS job.
 *
 * Extracted from AuthController so the OTP lifecycle (generate → store → send)
 * is a reusable, testable unit rather than duplicated inline code.
 */
final class RequestOtpAction
{
    /**
     * @param  User  $user  The user that will receive the code
     * @param  string  $mobile  Destination mobile number (canonical 09… format)
     * @param  int  $ttlMinutes  How long the code is valid (default: 2 min)
     */
    public function execute(User $user, string $mobile, int $ttlMinutes = 2): int
    {
        $code = generateVerifyCode();

        // فقط هش در DB می‌نشیند (OtpSecret) — مقدار خام تنها در SMS می‌رود
        $user->update([
            User::SECRET => OtpSecret::hash((string) $code),
            User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes($ttlMinutes),
        ]);

        SendSmsJob::template($mobile, 'loginMessage', ['code' => $code]);

        return $code;
    }
}
