<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ورودِ دومرحله‌ای با TOTP (RFC 6238) — تصمیمِ مدیریت شماره‌ی ۸.
 *
 * سه مرحله: `setup` راز می‌سازد (تأییدنشده و در ورود بی‌اثر) → `confirm` با
 * اولین کدِ درستِ اپ فعال می‌کند و ده کدِ بازیابی را **فقط همین یک بار** به
 * متن برمی‌گرداند → `disable` با یک کدِ معتبر (TOTP یا بازیابی) خاموش می‌کند.
 * خودِ ورود در `AuthController::verifyTwoFactor` است.
 */
class TwoFactorController extends Controller
{
    private const ISSUER = 'EliteZone';

    public function show(Request $request): JsonResponse
    {
        $row = $request->user()->twoFactor()->first();

        return success([
            'enabled' => $row?->isEnabled() ?? false,
            'enabled_at' => $row?->{UserTwoFactor::ENABLED_AT},
            'recovery_codes_left' => count($row->{UserTwoFactor::RECOVERY_CODES} ?? []),
        ], __('auth.two_factor_status'));
    }

    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return error(__('auth.two_factor_already_enabled'), [], 409);
        }

        // setupِ دوباره پیش از confirm راز را عوض می‌کند — رازِ قبلی هرگز فعال نشده بود.
        $row = UserTwoFactor::updateOrCreate(
            [UserTwoFactor::USER_ID => $user->id],
            [UserTwoFactor::SECRET => Totp::generateSecret(), UserTwoFactor::ENABLED_AT => null, UserTwoFactor::RECOVERY_CODES => null, UserTwoFactor::LAST_USED_STEP => null],
        );

        return success([
            'secret' => $row->{UserTwoFactor::SECRET},
            'otpauth_uri' => Totp::otpauthUri($row->{UserTwoFactor::SECRET}, $user->{User::MOBILE}, self::ISSUER),
        ], __('auth.two_factor_setup_ready'));
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'digits:6']], ['code.digits' => __('auth.code_digits')]);

        $row = $request->user()->twoFactor()->first();

        if ($row === null) {
            return error(__('auth.two_factor_not_set_up'), [], 409);
        }
        if ($row->isEnabled()) {
            return error(__('auth.two_factor_already_enabled'), [], 409);
        }
        if (! $row->consumeTotp((string) $request->input('code'))) {
            return error(__('auth.two_factor_code_invalid'), [], 422);
        }

        $row->forceFill([UserTwoFactor::ENABLED_AT => now()])->save();

        return success([
            'enabled' => true,
            'recovery_codes' => $row->issueRecoveryCodes(),
        ], __('auth.two_factor_enabled'));
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        $row = $request->user()->twoFactor()->first();

        if ($row === null || ! $row->isEnabled()) {
            return error(__('auth.two_factor_not_enabled'), [], 409);
        }

        $code = (string) $request->input('code');
        if (! $row->consumeTotp($code) && ! $row->consumeRecoveryCode($code)) {
            return error(__('auth.two_factor_code_invalid'), [], 422);
        }

        $row->delete();

        return success(['enabled' => false], __('auth.two_factor_disabled'));
    }
}
