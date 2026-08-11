<?php

namespace App\Services\PodiumServices;

use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CompanyInquiryService
{
    public function fetchByNationalId(string $nationalId): array
    {
        $endpoint = config('podium.endpoints.do_service_call', '/srv/sc/nzh/doServiceCall');
        $url = rtrim(config('podium.base_url'), '/').$endpoint;

        $headers = [
            'accept' => 'application/json',
            '_token_' => (string) config('podium.business_token'),
            '_token_issuer_' => (string) config('podium.defaults._token_issuer', '1'),
            'scWrapper' => 'true',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $serviceConfig = Config::get('services.company_inquiry');

        // تنها سرویسِ پادیوم بود که این گارد را نداشت — همهٔ خواهرهایش
        // (CardInquiry، CardToIban، IdentityInquirySc2، MobileNidMatch) دارند.
        //
        // تا وقتی کلیدِ واقعی در فالبکِ `env()` نشسته بود این تفاوت دیده نمی‌شد،
        // چون کلید همیشه مقدار داشت. با حذفِ آن فالبک (کامیت 0d30c06) این مسیر
        // شروع کرد به فرستادنِ درخواست با `scApiKey = null` و خطای عمومیِ سمتِ
        // پادیوم می‌گرفت — یعنی «کردنشیال تنظیم نشده» به شکلِ «سرویس خراب است»
        // ظاهر می‌شد. حالا صریح می‌گوید چه چیزی کم است.
        if (empty($serviceConfig['sc_api_key']) || empty($serviceConfig['sc_product_id'])) {
            throw new InvalidArgumentException(
                'Company Inquiry service not configured (PODIUM_SC_API_KEY_COMPANY/PODIUM_SC_PRODUCT_ID_COMPANY missing).'
            );
        }

        $body = [
            'scProductId' => $serviceConfig['sc_product_id'],
            'scApiKey' => $serviceConfig['sc_api_key'],
            'nationalNumber' => $nationalId,
        ];

        try {
            // 4. ارسال درخواست به صورت Form (x-www-form-urlencoded)
            $resp = Http::asForm()
                ->timeout((int) config('podium.defaults.timeout', 30))
                ->withHeaders($headers)
                ->post($url, $body);

            $status = $resp->status();
            $raw = $resp->body();
            $json = $resp->json();

            if (! $resp->successful()) {
                Log::error('[CompanyInquiry] HTTP error', ['status' => $status, 'raw' => $raw]);

                throw new Exception('خطا در ارتباط با سرویس پادیوم (HTTP '.$status.')');
            }

            if (! is_array($json)) {
                $json = json_decode($raw, true);
            }

            $normalized = $this->normalizeResponse($json);

            if ($normalized['hasError']) {
                Log::error('Company Inquiry Error: '.($normalized['message'] ?? 'Unknown'));
                throw new Exception($normalized['message'] ?? 'خطا در دریافت اطلاعات شرکت');
            }

            return $normalized['result'] ?? [];

        } catch (\Throwable $e) {
            Log::error('Company Inquiry Exception: '.$e->getMessage());
            throw $e;
        }
    }

    private function normalizeResponse($json): array
    {
        if (isset($json['result']['result']) && is_string($json['result']['result'])) {
            $decoded = json_decode($json['result']['result'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json['result'] = $decoded;
            }
        } elseif (isset($json['result']) && is_string($json['result'])) {
            $decoded = json_decode($json['result'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json['result'] = $decoded;
            }
        }

        return [
            'hasError' => $json['hasError'] ?? true,
            'message' => $json['message'] ?? null,
            'errorCode' => $json['errorCode'] ?? null,
            'result' => $json['result'] ?? [],
        ];
    }
}
