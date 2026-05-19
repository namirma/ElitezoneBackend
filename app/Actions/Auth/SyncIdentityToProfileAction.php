<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\PodiumClient;
use Throwable;

/**
 * Fetch identity info from Podium SC2 and upsert the user's UserProfile record.
 *
 * Called after a successful OTP verification so that the profile is pre-filled
 * with the legal name / birth-date returned by the identity bureau.
 */
final class SyncIdentityToProfileAction
{
    public function __construct(protected PodiumClient $client) {}

    public function execute(User $user): void
    {
        if (empty($user->national_code) || empty($user->birth_date)) {
            return;
        }

        $payload = [
            'apiKey' => (string) config('podium.services.identity_inquiry.sc_api_key'),
            'productEntityId' => (int) config('podium.services.identity_inquiry.sc_product_id'),
            'providerParameters' => [
                'nationalCode' => (string) $user->national_code,
                'birthDate' => (string) $user->birth_date,
            ],
            'asyncCall' => false,
            'wrapper' => true,
        ];

        try {
            $norm = PodiumClient::normalize($this->client->callSc2($payload));
        } catch (Throwable) {
            // Non-critical: silently skip if Podium is unavailable
            return;
        }

        $info = data_get($norm, 'result.identityInfo', []);
        $fname = (string) data_get($info, 'firstName', '');
        $lname = (string) data_get($info, 'lastName', '');
        $bdate = (string) data_get($info, 'birthDate', data_get($norm, 'result.birthDate', ''));

        if (preg_match('/^\d{8}$/', $bdate)) {
            $bdate = substr($bdate, 0, 4).'-'.substr($bdate, 4, 2).'-'.substr($bdate, 6, 2);
        }

        UserProfile::updateOrCreate(
            [UserProfile::USER_ID => $user->id],
            [
                UserProfile::USER_ID => $user->id,
                UserProfile::NAME => $fname !== '' ? $fname : '—',
                UserProfile::FAMILY => $lname !== '' ? $lname : '—',
                UserProfile::NATIONAL_CODE => (string) $user->national_code,
                UserProfile::BIRTHDAY => $bdate !== '' ? $bdate : '0000-00-00',
                UserProfile::PHONE => $user->mobile,
            ]
        );
    }
}
