<?php

namespace App\Services\PodiumServices;

use App\Services\PodiumClient;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

class IdentityInquirySc2Service
{
    public function __construct(protected PodiumClient $client) {}

    protected function cfg(): array
    {
        $svc = Config::get('podium.services.identity_inquiry', []);
        if (empty($svc['sc_product_id']) || empty($svc['sc_api_key'])) {
            throw new InvalidArgumentException('Identity Inquiry service not configured (sc_product_id/sc_api_key missing).');
        }

        return $svc;
    }

    protected function normalizeBirthDate(string $birthDate): string
    {
        return preg_replace('/-/', '', $birthDate);
    }

    public function inquire(string $nationalCode, string $birthDateJalali): array
    {
        $svc = $this->cfg();

        $payload = [
            'apiKey' => $svc['sc_api_key'],
            'productEntityId' => (int) $svc['sc_product_id'],
            'providerParameters' => [
                'nationalCode' => $nationalCode,
                'birthDate' => $this->normalizeBirthDate($birthDateJalali),
            ],
            'asyncCall' => false,
            'wrapper' => true,
        ];

        $res = $this->client->callSc2($payload);

        return PodiumClient::normalize($res);
    }

    /**
     * Map the normalized Podium response to the frontend-facing identity payload.
     * Extracts identityInfo fields and formats birth_date to YYYY-MM-DD.
     */
    public function mapIdentityResponse(array $normalized): array
    {
        $info = data_get($normalized, 'result.identityInfo', []);

        $bd = (string) data_get($normalized, 'result.birthDate', '');
        if (preg_match('/^\d{8}$/', $bd)) {
            $bd = substr($bd, 0, 4).'-'.substr($bd, 4, 2).'-'.substr($bd, 6, 2);
        }

        return [
            'reference_number' => (string) ($normalized['reference_number'] ?? ''),
            'national_code' => (string) data_get($normalized, 'result.nationalCode', ''),
            'birth_date' => $bd,
            'first_name' => (string) ($info['firstName'] ?? ''),
            'last_name' => (string) ($info['lastName'] ?? ''),
            'father_name' => (string) ($info['fatherName'] ?? ''),
            'gender' => (string) ($info['gender'] ?? ''),
            'alive' => $this->normalizeBool($info['alive'] ?? null),
            'birth_place' => (string) ($info['birthPlace'] ?? ''),
            'birth_place_code' => (string) ($info['birthPlaceCode'] ?? ''),
            'identification_number' => (string) ($info['identificationNumber'] ?? ''),
            'identification_serial_code' => (string) ($info['identificationSerialCode'] ?? ''),
            'identification_serial_number' => (string) ($info['identificationSerialNumber'] ?? ''),
        ];
    }

    private function normalizeBool(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $str = strtolower((string) $value);
        if (in_array($str, ['true', 'false', 'null'], true)) {
            return $str;
        }

        return 'null';
    }
}
