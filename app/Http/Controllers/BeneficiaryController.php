<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserType;
use App\Services\PodiumClient;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Throwable;

class BeneficiaryController extends Controller
{
    public function startVerification(Request $request, PodiumClient $client): JsonResponse
    {
        $data = $request->validate([
            'national_code' => ['required', 'regex:/^\d{10}$/'],
            'birth_date'    => ['required', 'regex:/^(\d{8}|\d{4}-\d{2}-\d{2})$/'],
            'mobile_number' => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
        ]);

        $buyer = Auth::user();
        $mobile = $this->mobileTo09($data['mobile_number']);
        $nationalCode = (string) $data['national_code'];
        $birthDate = str_replace('-', '', (string) $data['birth_date']);

        if ($buyer->{User::MOBILE} == $mobile || $buyer->{User::NATIONAL_CODE} == $nationalCode) {
            return error(__('beneficiary.cannot_be_self'), [], 422);
        }

        $matchPayload = [
            'apiKey'          => (string) config('podium.services.nid_mobile_match.sc_api_key'),
            'productEntityId' => (int)    config('podium.services.nid_mobile_match.sc_product_id'),
            'providerParameters' => ['body' => ['nationalCode' => $nationalCode, 'mobileNumber' => $mobile]],
            'asyncCall' => false, 'wrapper'   => true,
        ];

        try {
            $matchRes = PodiumClient::normalize($client->callSc2($matchPayload));
            $matched   = data_get($matchRes, 'result.matched');
            $matchCode = (string) data_get($matchRes, 'result.resultCode', '');
            if (!($matched === true || $matched === 'true' || $matchCode === '1')) {
                 return error(__('beneficiary.mobile_nid_mismatch'), [], 422); // 🚀 Updated key
            }
        } catch (Throwable $e) {
            return error(__('beneficiary.mobile_check_failed'), [], 502); // 🚀 Updated key
        }

        $idenPayload = [
            'apiKey'             => (string) config('podium.services.identity_inquiry.sc_api_key'),
            'productEntityId'    => (int)    config('podium.services.identity_inquiry.sc_product_id'),
            'providerParameters' => ['nationalCode' => $nationalCode, 'birthDate' => $birthDate],
            'asyncCall' => false, 'wrapper'   => true,
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
                User::BIRTH_DATE    => $birthDate,
                User::TYPE_ID       => UserType::where('slug', 'employer')->value('id'),
            ]
        );

        $info  = data_get($idenRes, 'result.identityInfo', []);
        $fname = (string) data_get($info, 'firstName', '');
        $lname = (string) data_get($info, 'lastName', '');

        UserProfile::updateOrCreate(
            [UserProfile::USER_ID => $beneficiaryUser->id],
            [
                UserProfile::NAME => $fname,
                UserProfile::FAMILY => $lname,
                UserProfile::NATIONAL_CODE => $nationalCode,
                UserProfile::BIRTHDAY => substr($birthDate, 0, 4) . '-' . substr($birthDate, 4, 2) . '-' . substr($birthDate, 6, 2),
            ]
        );

        $verifyCode = generateVerifyCode();
        $beneficiaryUser->update([
            User::SECRET            => $verifyCode,
            User::SECRET_EXPIRES_AT => Carbon::now()->addMinutes(3),
        ]);

        $buyerName = $buyer->profile->{UserProfile::NAME} ?? $buyer->{User::MOBILE};
        $smsMessage = __('beneficiary.sms_verification_body', ['name' => $buyerName, 'code' => $verifyCode]);
        (new SmsService())->send($mobile, $smsMessage);

        return success([
            'beneficiary_user_id' => $beneficiaryUser->id,
            'name'                => $fname,
            'family'              => $lname,
        ], __('beneficiary.code_sent_successfully'));
    }

    public function confirmVerification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'beneficiary_user_id' => 'required|exists:users,id',
            'code'                => 'required|digits:6',
        ]);

        $beneficiaryUser = User::find($data['beneficiary_user_id']);

        if (!$beneficiaryUser ||
            $beneficiaryUser->{User::SECRET} != $data['code'] ||
            Carbon::parse($beneficiaryUser->{User::SECRET_EXPIRES_AT})->isPast())
        {
            return error(__('auth.verification_code_not_match'), [], 400);
        }

        $beneficiaryUser->update([
            User::SECRET => null,
            User::SECRET_EXPIRES_AT => null,
        ]);

        $beneficiaryUser->load('profile');

        return success([
            'beneficiary_user_id' => $beneficiaryUser->id,
            'name'                => $beneficiaryUser->profile->{UserProfile::NAME} ?? '',
            'family'              => $beneficiaryUser->profile->{UserProfile::FAMILY} ?? '',
        ], __('beneficiary.verification_successful'));
    }

    private function mobileTo09(string $input): string
    {
        $m = preg_replace('/\D/', '', $input);
        if (str_starts_with($m, '0098')) $m = substr($m, 2);
        if (str_starts_with($m, '98'))   return '0' . substr($m, 2);
        if (str_starts_with($m, '9'))    return '0' . $m;
        return $m;
    }
}
