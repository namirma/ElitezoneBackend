<?php

namespace App\Actions\Auth;

use App\Services\PodiumClient;
use Throwable;

/**
 * Calls two Podium SC2 services to verify an Iranian identity:
 *   1. NID ↔ Mobile match
 *   2. NID ↔ BirthDate identity inquiry
 *
 * Returns null on success; returns a string error message on failure.
 * Throwing is avoided so the caller (controller/action) decides HTTP status.
 */
final class VerifyIdentityWithPodiumAction
{
    public function __construct(protected PodiumClient $client) {}

    /**
     * @return string|null null = verified OK; string = human-readable error key
     */
    public function execute(string $mobile, string $nationalCode, string $birthDate): ?string
    {
        // Step 1: NID ↔ Mobile match
        $matchError = $this->checkMobileNidMatch($mobile, $nationalCode);
        if ($matchError) {
            return $matchError;
        }

        // Step 2: NID ↔ BirthDate inquiry
        return $this->checkBirthDate($nationalCode, $birthDate);
    }

    private function checkMobileNidMatch(string $mobile, string $nationalCode): ?string
    {
        $payload = [
            'apiKey' => (string) config('podium.services.nid_mobile_match.sc_api_key'),
            'productEntityId' => (int) config('podium.services.nid_mobile_match.sc_product_id'),
            'providerParameters' => [
                'body' => ['nationalCode' => $nationalCode, 'mobileNumber' => $mobile],
            ],
            'asyncCall' => false,
            'wrapper' => true,
        ];

        try {
            $res = PodiumClient::normalize($this->client->callSc2($payload));
        } catch (Throwable) {
            return 'auth.mobile_check_failed';
        }

        $matched = data_get($res, 'result.matched');
        $matchCode = (string) data_get($res, 'result.resultCode', '');
        $isMatch = $matched === true || $matched === 'true' || $matchCode === '1';

        return $isMatch ? null : 'auth.mobile_nid_mismatch';
    }

    private function checkBirthDate(string $nationalCode, string $birthDate): ?string
    {
        $payload = [
            'apiKey' => (string) config('podium.services.identity_inquiry.sc_api_key'),
            'productEntityId' => (int) config('podium.services.identity_inquiry.sc_product_id'),
            'providerParameters' => ['nationalCode' => $nationalCode, 'birthDate' => $birthDate],
            'asyncCall' => false,
            'wrapper' => true,
        ];

        try {
            $res = PodiumClient::normalize($this->client->callSc2($payload));
        } catch (Throwable) {
            return 'auth.birthdate_check_failed';
        }

        $srvBirth = (string) data_get(
            $res,
            'result.identityInfo.birthDate',
            data_get($res, 'result.birthDate', '')
        );

        return ($srvBirth === '' || $srvBirth !== $birthDate)
            ? 'auth.birthdate_mismatch'
            : null;
    }
}
