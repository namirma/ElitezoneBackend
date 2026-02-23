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
            'apiKey'          => $svc['sc_api_key'],
            'productEntityId' => (int) $svc['sc_product_id'],
            'providerParameters' => [
                'nationalCode' => $nationalCode,
                'birthDate'    => $this->normalizeBirthDate($birthDateJalali),
            ],
            'asyncCall' => false,
            'wrapper'   => true,
        ];

        $res  = $this->client->callSc2($payload);
        return PodiumClient::normalize($res);
    }
}
