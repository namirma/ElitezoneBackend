<?php

namespace App\Services\PodiumServices;

use App\Services\PodiumClient;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

class MobileNidMatchService
{
    public function __construct(protected PodiumClient $client) {}

    protected function cfg(): array
    {
        $svc = Config::get('podium.services.nid_mobile_match', []);
        if (empty($svc['sc_product_id']) || empty($svc['sc_api_key'])) {
            throw new InvalidArgumentException('Mobile-NID Match service not configured (sc_product_id/sc_api_key missing).');
        }

        return $svc;
    }

    public function match(string $nationalCode, string $mobileNumber): array
    {
        $svc = $this->cfg();
        $mobile = PhoneNumber::to09($mobileNumber);

        $payload = [
            'apiKey' => $svc['sc_api_key'],
            'productEntityId' => (int) $svc['sc_product_id'],
            'providerParameters' => [
                'body' => [
                    'nationalCode' => $nationalCode,
                    'mobileNumber' => $mobile,
                ],
            ],
            'asyncCall' => false,
            'wrapper' => true,
        ];

        $res = $this->client->callSc2($payload);

        return PodiumClient::normalize($res);
    }

    /**
     * Map the normalized Podium response to the frontend-facing match payload.
     * Resolves matched status from result.matched or result.resultCode.
     */
    public function mapMatchResponse(array $normalized, string $nationalCode, string $mobileNumber): array
    {
        $result = $normalized['result'] ?? [];
        $matched = data_get($result, 'matched');
        $resultCode = (string) data_get($result, 'resultCode', '');
        $provMsg = (string) data_get($result, 'message', '');

        $isSuccess = $this->resolveMatchStatus($matched, $resultCode);

        $message = $provMsg !== ''
            ? $provMsg
            : match ($isSuccess) {
                'true' => 'تطابق تأیید شد',
                'false' => 'مغایرت اطلاعات',
                default => 'نتیجه نامشخص',
            };

        return [
            'payload' => [
                'reference_number' => (string) ($normalized['reference_number'] ?? ''),
                'national_code' => (string) $nationalCode,
                'mobile_number' => PhoneNumber::to09($mobileNumber),
                'is_success' => $isSuccess,
                'result_code' => $resultCode,
                'provider_message' => $provMsg,
            ],
            'message' => $message,
        ];
    }

    private function resolveMatchStatus(mixed $matched, string $resultCode): string
    {
        if (is_bool($matched)) {
            return $matched ? 'true' : 'false';
        }

        $str = strtolower((string) $matched);
        if (in_array($str, ['true', 'false', 'null'], true)) {
            return $str;
        }

        if ($resultCode !== '') {
            return match ($resultCode) {
                '1' => 'true',
                '0' => 'false',
                default => 'null',
            };
        }

        return 'null';
    }
}
