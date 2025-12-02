<?php

namespace App\Services\PodiumServices;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Exception;

class CardInquiryService
{
    public function verify(string $cardNumber, string $nationalCode, string $birthDate): bool
    {
        $birthDate = preg_replace('/[^0-9]/', '', $birthDate);

        $endpoint = config('podium.endpoints.do_service_call', '/srv/sc/nzh/doServiceCall');
        $url = rtrim(config('podium.base_url', 'https://api.pod.ir'), '/') . $endpoint;
        $headers = [
            'accept'         => 'application/json',
            '_token_'        => (string) config('podium.business_token'),
            '_token_issuer_' => (string) config('podium.defaults._token_issuer', '1'),
            'scWrapper'      => 'true',
            'Content-Type'   => 'application/x-www-form-urlencoded',
        ];

        $serviceConfig = Config::get('services.card_inquiry');

        if (empty($serviceConfig['sc_api_key']) || empty($serviceConfig['sc_product_id'])) {
            Log::error('Card Service Config Missing');
            throw new Exception('تنظیمات سرویس استعلام کارت یافت نشد.');
        }
        $body = [
            'scProductId'  => $serviceConfig['sc_product_id'],
            'scApiKey'     => $serviceConfig['sc_api_key'],
            'cardNumber'   => $cardNumber,
            'nationalCode' => $nationalCode,
            'birthDate'    => $birthDate,
        ];

        try {
            $resp = Http::asForm()
                ->timeout(30)
                ->withHeaders($headers)
                ->post($url, $body);

            $json = $resp->json();

            if (!$resp->successful()) {
                Log::error('Card Inquiry HTTP Error', [
                    'status' => $resp->status(),
                    'body' => $resp->body()
                ]);
                throw new Exception('خطا در ارتباط با سرویس بانکی.');
            }
            if (!is_array($json)) {
                $json = json_decode($resp->body(), true);
            }
            if (($json['hasError'] ?? false) === true) {
                $msg = $json['message'] ?? 'خطای سرویس کارت';
                Log::error("Card API Business Error: $msg", ['response' => $json]);
                throw new Exception($msg);
            }
            $resultData = $json['result'] ?? [];
            if (isset($resultData['result']) && is_string($resultData['result'])) {
                 $innerResult = json_decode($resultData['result'], true);
                 $matched = $innerResult['matched'] ?? false;
            } elseif (isset($resultData['matched'])) {
                 $matched = $resultData['matched'];
            } else {
                 $matched = false;
            }

            return (bool) $matched;

        } catch (\Throwable $e) {
            Log::error('Card Inquiry Exception: ' . $e->getMessage());
            throw $e; 
        }
    }
}
