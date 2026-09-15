<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Jobs\Notification\SendSmsJob;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserType;
use App\Services\PodiumClient;
use App\Support\OtpSecret;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BeneficiaryController extends Controller
{
    /**
     * تأیید ذی‌نفع باید توسط همان خریداری انجام شود که فرایند را شروع کرده —
     * این کلید کش، جلسه‌ی تأیید را به جفت (خریدار، ذی‌نفع) گره می‌زند.
     */
    private function verificationSessionKey(int $buyerId, int $beneficiaryUserId): string
    {
        return "beneficiary_verification:{$buyerId}:{$beneficiaryUserId}";
    }

    public function startVerification(Request $request, PodiumClient $client): JsonResponse
    {
        $data = $request->validate([
            'national_code' => ['required', 'regex:/^\d{10}$/'],
            'birth_date' => ['required', 'regex:/^(\d{8}|\d{4}-\d{2}-\d{2})$/'],
            'mobile_number' => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
        ]);

        $buyer = Auth::user();
        $mobile = PhoneNumber::to09($data['mobile_number']);
        $nationalCode = (string) $data['national_code'];
        $birthDate = str_replace('-', '', (string) $data['birth_date']);

        if ((string) $buyer->{User::MOBILE} === $mobile || (string) $buyer->{User::NATIONAL_CODE} === $nationalCode) {
            return error(__('beneficiary.cannot_be_self'), [], 422);
        }

        $matchPayload = [
            'apiKey' => (string) config('podium.services.nid_mobile_match.sc_api_key'),
            'productEntityId' => (int) config('podium.services.nid_mobile_match.sc_product_id'),
            'providerParameters' => ['body' => ['nationalCode' => $nationalCode, 'mobileNumber' => $mobile]],
            'asyncCall' => false,
            'wrapper' => true,
        ];

        try {
            $matchRes = PodiumClient::normalize($client->callSc2($matchPayload));
            $matched = data_get($matchRes, 'result.matched');
            $matchCode = (string) data_get($matchRes, 'result.resultCode', '');
            if (! ($matched === true || $matched === 'true' || $matchCode === '1')) {
                return error(__('beneficiary.mobile_nid_mismatch'), [], 422); // 🚀 Updated key
            }
        } catch (Throwable $e) {
            return error(__('beneficiary.mobile_check_failed'), [], 502); // 🚀 Updated key
        }

        $idenPayload = [
            'apiKey' => (string) config('podium.services.identity_inquiry.sc_api_key'),
            'productEntityId' => (int) config('podium.services.identity_inquiry.sc_product_id'),
            'providerParameters' => ['nationalCode' => $nationalCode, 'birthDate' => $birthDate],
            'asyncCall' => false,
            'wrapper' => true,
        ];

        try {
            $idenRes = PodiumClient::normalize($client->callSc2($idenPayload));
            $srvBirth = (string) data_get($idenRes, 'result.identityInfo.birthDate', data_get($idenRes, 'result.birthDate', ''));
            if ($srvBirth === '' || $srvBirth !== $birthDate) {
                return error(__('beneficiary.birthdate_mismatch'), [], 422); // 🚀 Updated key
            }
        } catch (Throwable $e) {
            return error(__('beneficiary.birthdate_check_failed'), [], 502); // 🚀 Updated key
        }

        $beneficiaryUser = User::firstOrCreate(
            [User::MOBILE => $mobile],
            [
                User::NATIONAL_CODE => $nationalCode,
                User::BIRTH_DATE => $birthDate,
                User::TYPE_ID => UserType::where('slug', 'employer')->value('id'),
            ]
        );

        // الیت یار نمی‌تواند گیرندهٔ سفارش باشد — او کمیسیون‌گیرِ سفارش است، نه
        // کارفرمای آن. (ذی‌نفع و الیت یار دو شخصِ متفاوت روی یک سفارش‌اند.)
        //
        // قبلاً این چک سه اسلاگِ user_type را به شناسه ترجمه می‌کرد؛ حالا نقشِ
        // `elite_yar` تنها سیگنال است و engineer/contractor صرفاً وضعیتِ مدرک را
        // می‌گویند، نه اینکه کاربر الیت یار است.
        if ($beneficiaryUser->hasRole('elite_yar')) {
            return error(__('beneficiary.cannot_be_technical_role'), [], 422);
        }

        $info = data_get($idenRes, 'result.identityInfo', []);
        $fname = (string) data_get($info, 'firstName', '');
        $lname = (string) data_get($info, 'lastName', '');

        UserProfile::updateOrCreate(
            [UserProfile::USER_ID => $beneficiaryUser->id],
            [
                UserProfile::NAME => $fname,
                UserProfile::FAMILY => $lname,
                UserProfile::NATIONAL_CODE => $nationalCode,
                UserProfile::BIRTHDAY => substr($birthDate, 0, 4).'-'.substr($birthDate, 4, 2).'-'.substr($birthDate, 6, 2),
            ]
        );

        $verifyCode = generateVerifyCode();

        // کد تأیید در cache با کلید جفت (خریدار، ذی‌نفع) نگه‌داری می‌شود — نه در
        // users.secret ذی‌نفع. نوشتن در secret، کد OTP لاگینِ خودِ آن شخص را باطل
        // می‌کرد (و برعکس، لاگین او این کد را می‌سوزاند)؛ ستون secret فقط متعلق به
        // جریان‌های احراز هویت خود کاربر است.
        // هَش و نه خودِ کد: قاعده‌ی سراسری پروژه («OTP/reset-token storage is
        // hashed») به محلِ ذخیره کاری ندارد — کشِ پروژه درایورِ `database` است،
        // پس کدِ خام در جدولِ `cache` می‌نشیند، نه در حافظه‌ی فرّار.
        Cache::put(
            $this->verificationSessionKey((int) $buyer->id, (int) $beneficiaryUser->id),
            OtpSecret::hash((string) $verifyCode),
            now()->addMinutes(3)
        );

        $buyerName = $buyer->profile->{UserProfile::NAME} ?? $buyer->{User::MOBILE};
        // قالبِ `sms.beneficiary_verification` (همان متن) — تا در پنلِ پیامک‌ها دیده شود.
        SendSmsJob::template($mobile, 'beneficiary_verification', ['name' => $buyerName, 'code' => $verifyCode]);

        return success([
            'beneficiary_user_id' => $beneficiaryUser->id,
            'name' => $fname,
            'family' => $lname,
        ], __('beneficiary.code_sent_successfully'));
    }

    public function confirmVerification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'beneficiary_user_id' => 'required|exists:users,id',
            'code' => 'required|digits:6',
        ]);

        $beneficiaryUser = User::find($data['beneficiary_user_id']);

        // کد در cache با TTL سه دقیقه‌ای است — انقضا را خودِ cache مدیریت می‌کند.
        $sessionKey = $this->verificationSessionKey((int) Auth::id(), (int) $data['beneficiary_user_id']);
        $expectedCode = Cache::get($sessionKey);

        if ($expectedCode === null) {
            return error(__('beneficiary.verification_not_initiated'), [], 403);
        }

        // `OtpSecret::matches` خودش زمان‌ثابت است (bcrypt verify) — جایگزینِ
        // `hash_equals` روی مقدارِ خام.
        if (! $beneficiaryUser || ! OtpSecret::matches((string) $expectedCode, (string) $data['code'])) {
            return error(__('auth.verification_code_not_match'), [], 400);
        }

        // کد یکبارمصرف است — بلافاصله پس از موفقیت باطل می‌شود.
        Cache::forget($sessionKey);

        // ثبت رابطه الیت یار–مشتری برای تب «ثبت‌شدگان من»
        Auth::user()->registeredClients()->syncWithoutDetaching([$beneficiaryUser->id]);

        $beneficiaryUser->load('profile');

        return success([
            'beneficiary_user_id' => $beneficiaryUser->id,
            'name' => $beneficiaryUser->profile->{UserProfile::NAME} ?? '',
            'family' => $beneficiaryUser->profile->{UserProfile::FAMILY} ?? '',
        ], __('beneficiary.verification_successful'));
    }
}
