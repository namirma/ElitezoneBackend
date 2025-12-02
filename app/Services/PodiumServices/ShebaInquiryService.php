<?php

namespace App\Services\PodiumServices;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Exception;

class ShebaInquiryService
{
    public function verify(string $iban, string $nationalCode, string $birthDate): bool
    {
        $birthDate = preg_replace('/[^0-9]/', '', $birthDate);

        // 2. تنظیم آدرس و هدرها
        $endpoint = config('podium.endpoints.do_service_call', '/srv/sc/nzh/doServiceCall');
        $url = rtrim(config('podium.base_url'), '/') . $endpoint;

        $headers = [
            'accept'         => 'application/json',
            '_token_'        => (string) config('podium.business_token'), // توکن کسب‌وکار
            '_token_issuer_' => (string) config('podium.defaults._token_issuer', '1'),
            'scWrapper'      => 'true',
            'Content-Type'   => 'application/x-www-form-urlencoded',
        ];

        // 3. دریافت تنظیمات سرویس
        $serviceConfig = Config::get('services.sheba_inquiry');
        if (empty($serviceConfig['sc_api_key']) || empty($serviceConfig['sc_product_id'])) {
            throw new Exception('تنظیمات سرویس استعلام شبا یافت نشد.');
        }

        // 4. ساخت بدنه درخواست
        $body = [
            'scProductId'  => $serviceConfig['sc_product_id'],
            'scApiKey'     => $serviceConfig['sc_api_key'],
            'iban'         => $iban,
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
                Log::error('Sheba Inquiry HTTP Error', ['status' => $resp->status(), 'body' => $resp->body()]);
                throw new Exception('خطا در ارتباط با سرویس بانکی.');
            }

            $hasError = $json['hasError'] ?? true;

            if ($hasError) {
                $msg = $json['message'] ?? 'خطای ناشناخته در سرویس شبا';
                Log::error("Sheba API Error: $msg");
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
            Log::error('Sheba Inquiry Exception: ' . $e->getMessage());

            throw new Exception('سرویس استعلام شبا در دسترس نیست یا اطلاعات نامعتبر است.');
        }
    }
}
