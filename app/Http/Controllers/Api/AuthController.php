<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserProfile;
use App\Models\UserType;
use App\Services\PodiumClient;
use App\Services\SmsService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class AuthController extends Controller
{
    public function register(StoreUserRequest $request): JsonResponse
    {
        $mobile       = $this->mobileTo09($request->validated('username'));
        $nationalCode = (string) $request->validated('national_code');
        $birthDate    = str_replace('-', '', (string) $request->validated('birth_date'));

        $user = User::where(User::MOBILE, $mobile)->first();

        if ($user) {
            if (isset($user->{User::SECRET_EXPIRES_AT}) && ! Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()) {
                return response()->json(['status' => true, 'message' => __('auth.code_sent_recently')], 202);
            }
            $verifyCode = generateVerifyCode();

            $user->update([
                User::SECRET            => $verifyCode,
                User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2),
            ]);

            (new SmsService())->send($mobile, __('sms.loginMessage', ['code' => $verifyCode]));

            return response()->json(['status' => true, 'message' => __('auth.verification_code_sent_successfully')], 202);
        } else {

            $client = app(PodiumClient::class);

            $matchPayload = [
                'apiKey'          => (string) config('podium.services.nid_mobile_match.sc_api_key'),
                'productEntityId' => (int)    config('podium.services.nid_mobile_match.sc_product_id'),
                'providerParameters' => [
                    'body' => [
                        'nationalCode' => $nationalCode,
                        'mobileNumber' => $mobile,
                    ],
                ],
                'asyncCall' => false,
                'wrapper'   => true,
            ];

            try {
                $matchRes = PodiumClient::normalize($client->callSc2($matchPayload));
            } catch (Throwable $e) {
                return response()->json(['status' => false, 'message' => __('auth.mobile_check_failed')], 502);
            }

            $matched   = data_get($matchRes, 'result.matched');
            $matchCode = (string) data_get($matchRes, 'result.resultCode', '');
            $isMatch   = $matched === true || $matched === 'true' || $matchCode === '1';

            if (! $isMatch) {
                return response()->json(['status' => false, 'message' => __('auth.mobile_nid_mismatch')], 422);
            }

            $idenPayload = [
                'apiKey'             => (string) config('podium.services.identity_inquiry.sc_api_key'),
                'productEntityId'    => (int)    config('podium.services.identity_inquiry.sc_product_id'),
                'providerParameters' => [
                    'nationalCode' => $nationalCode,
                    'birthDate'    => $birthDate,
                ],
                'asyncCall' => false,
                'wrapper'   => true,
            ];

            try {
                $idenRes = PodiumClient::normalize($client->callSc2($idenPayload));
            } catch (Throwable $e) {
                return response()->json(['status' => false, 'message' => __('auth.birthdate_check_failed')], 502);
            }

            $srvBirth = (string) data_get($idenRes, 'result.identityInfo.birthDate', data_get($idenRes, 'result.birthDate', ''));
            if ($srvBirth === '' || $srvBirth !== $birthDate) {
                return response()->json(['status' => false, 'message' => __('auth.birthdate_mismatch')], 422);
            }

            $verifyCode = generateVerifyCode();

            User::create(
                [
                    User::MOBILE            => $mobile,
                    User::NATIONAL_CODE     => $nationalCode,
                    User::BIRTH_DATE        => $birthDate,
                    User::SECRET            => $verifyCode,
                    User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2),
                    User::TYPE_ID           => null,
                ]
            );

            (new SmsService())->send($mobile, __('sms.loginMessage', ['code' => $verifyCode]));

            return response()->json(['status' => true, 'message' => __('auth.verification_code_sent_successfully')], 202);
        }
    }

    public function checkUserAndSendCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/']
        ]);

        $mobile = $this->mobileTo09($validated['username']);
        $user = User::where(User::MOBILE, $mobile)->first();

        if ($user) {
            if ($user->{User::PASSWORD}) {
            }
            if (isset($user->{User::SECRET_EXPIRES_AT}) && ! Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()) {
                return response()->json([
                    'status' => true,
                    'message' => __('auth.code_sent_recently'),
                    'action' => 'login'
                ], 202);
            }

            $verifyCode = generateVerifyCode();
            $user->update([
                User::SECRET            => $verifyCode,
                User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2),
            ]);

            (new SmsService())->send($mobile, __('sms.loginMessage', ['code' => $verifyCode]));

            return response()->json([
                'status' => true,
                'message' => __('auth.verification_code_sent_successfully'),
                'action' => 'login'
            ], 200);
        } else {

            return response()->json([
                'status' => false,
                'message' => __('auth.no_user_found_register_required'),
                'action' => 'register'
            ], 404);
        }
    }
    private function mobileTo09(string $input): string
    {
        $m = preg_replace('/\D/', '', $input);
        if (str_starts_with($m, '0098')) $m = substr($m, 2);
        if (str_starts_with($m, '98'))   return '0' . substr($m, 2);
        if (str_starts_with($m, '9'))    return '0' . $m;
        return $m;
    }
    public function types(): JsonResponse
    {
        return success(UserType::all(), __('global.information_received_successfully'));
    }

    private function syncIdentityToProfile(User $user): void
    {
        if (empty($user->national_code) || empty($user->birth_date)) {
            return;
        }

        $client  = app(PodiumClient::class);
        $payload = [
            'apiKey'             => (string) config('podium.services.identity_inquiry.sc_api_key'),
            'productEntityId'    => (int)    config('podium.services.identity_inquiry.sc_product_id'),
            'providerParameters' => [
                'nationalCode' => (string) $user->national_code,
                'birthDate'    => (string) $user->birth_date, // 13790922
            ],
            'asyncCall' => false,
            'wrapper'   => true,
        ];

        try {
            $norm = PodiumClient::normalize($client->callSc2($payload));
        } catch (Throwable $e) {
            return;
        }

        $info  = data_get($norm, 'result.identityInfo', []);
        $fname = (string) data_get($info, 'firstName', '');
        $lname = (string) data_get($info, 'lastName', '');
        $bdate = (string) data_get($info, 'birthDate', data_get($norm, 'result.birthDate', ''));

        if (preg_match('/^\d{8}$/', $bdate)) {
            $bdate = substr($bdate, 0, 4) . '-' . substr($bdate, 4, 2) . '-' . substr($bdate, 6, 2); // 1379-09-22
        }
        $values = [
            UserProfile::USER_ID       => $user->id,
            UserProfile::NAME          => $fname !== '' ? $fname : '—',
            UserProfile::FAMILY        => $lname !== '' ? $lname : '—',
            UserProfile::NATIONAL_CODE => (string) $user->national_code,
            UserProfile::BIRTHDAY      => $bdate !== '' ? $bdate : '0000-00-00',
            UserProfile::PHONE         => $user->mobile,
        ];

        UserProfile::updateOrCreate(
            [UserProfile::USER_ID => $user->id],
            $values
        );
    }
    public function verifyCode(StoreUserRequest $request): JsonResponse
    {
        $user = User::where([
            User::MOBILE => $request->validated('username'),
            User::SECRET => $request->validated('code'),
        ])->first();

        if (! $user || Carbon::parse(@$user->secret_expires_at)->isPast()) {
            return error(__('auth.verification_code_not_match'), [], 404);
        }

        $createdAt = $user->created_at instanceof Carbon ? $user->created_at : Carbon::parse($user->created_at);
        $isNewUser = $user->wasRecentlyCreated || $createdAt->diffInMinutes(now()) < 2;

        if ($request->has('forget_password') && $request->validated('forget_password')) {
            return success([]);
        }

        Auth::login($user);
        $roles = (array) $request->input('roles', []);
        $user  = Auth::user();

        foreach ($roles as $role) {
            match ($role) {
                'engineer'   => $user->engineer()->firstOrCreate([]),
                'contractor' => $user->contractor()->firstOrCreate([]),
                'employer'   => $user->employer()->firstOrCreate([]),
                'supplier'   => $user->supplier()->firstOrCreate([]),
                default      => null,
            };
        }

        $this->syncIdentityToProfile($user);

        $user->tokens()->delete();
        $token = $user->createToken('auth')->plainTextToken;

        if ($isNewUser) {
            (new SmsService())->send($request->validated('username'), __('sms.newRegisterUser'));
            UserNotification::create([
                UserNotification::USER_ID => Auth::id(),
                UserNotification::SUBJECT => __('global.newUserNotificationSubject'),
                UserNotification::BODY    => __('global.newUserNotificationBody'),
            ]);
        }

        return success(['user' => new UserResource($user->fresh('profile')), 'token' => $token], __('auth.register_success'));
    }

    public function checkPassword(Request $request)
    {
        $user = User::where([User::MOBILE => $request->input('username')])->firstOrFail();

        if (Hash::check($request->input('password'), $user->password)) {
            Auth::login($user);
            $user->tokens()->delete();
            $token = $user->createToken('auth')->plainTextToken;

            return success(['user' => new UserResource($user), 'token' => $token], __('auth.login_success'));
        }

        return error(__('auth.password_error'), [], 404);
    }

    public function forgetPassword(Request $request)
    {
        $request->validate([
            'username' => 'required|exists:users,mobile'
        ]);

        $user = User::where([User::MOBILE => $request->input('username')])->where(User::PASSWORD, '!=', null)->first();
        if (!is_null($user)) {

            $mobileVerifyCode = generateVerifyCode();
            $user->update([
                User::SECRET => $mobileVerifyCode,
                User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(3)
            ]);

            (new SmsService())->send(
                $request->input('username'),
                __('sms.loginMessage', ['code' => $mobileVerifyCode])
            );;
            return success([], 'auth.forget_password_code_sent_successfully');
        }

        return error(__('auth.no_user_found'), [], 404);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'username' => 'required|exists:users,mobile',
            'code' => 'required',
            User::PASSWORD => 'required|confirmed'
        ]);

        $user = User::where([
            User::MOBILE => $request->input('username'),
            User::SECRET => $request->input('code')
        ])
            ->where(User::PASSWORD, '!=', null)
            ->first();

        if (Carbon::parse(@$user->secret_expires_at)->isPast() && is_null($user))
            return error('something went wrong', [], 400);

        $user->update([User::PASSWORD => Hash::make($request->input('password'))]);

        Auth::login($user);
        $user->tokens()->delete();
        $token = $user->createToken('auth')->plainTextToken;
        return success(['user' => new UserResource($user), 'token' => $token], __('auth.password_changed_successfully'));
    }

    public function sendVerificationCode(StoreUserRequest $request)
    {
        $request->validate([
            'username' => 'required|exists:users,mobile'
        ]);

        $user = User::where([User::MOBILE => $request->input('username')])->first();

        $mobileVerifyCode = generateVerifyCode();
        $user->update([
            User::SECRET => $mobileVerifyCode,
            User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(2)
        ]);

        (new SmsService())->send(
            $request->validated('username'),
            __('sms.loginMessage', ['code' => $mobileVerifyCode])
        );

        return success([], __('auth.verification_code_sent_successfully'));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return error(__('auth.unauthenticated'), [], 401);
        }
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return success([], __('auth.logout_success'));
    }
}
