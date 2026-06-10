<?php

namespace App\Services\PodiumServices;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * تبدیل شماره کارت به شبا (سرویس کارت به شبا پادیوم).
 *
 * برخلاف سرویس‌های verify، این سرویس «تکمیل‌کننده» است نه «گارد»:
 * شکست آن نباید ثبت حساب بانکی را متوقف کند — در صورت خطا یا نبود تنظیمات
 * null برمی‌گرداند و حساب فقط با شماره کارت ذخیره می‌شود.
 *
 * نکته: عکس این عملیات (شبا → کارت) در شبکه بانکی ایران سرویس استعلام ندارد.
 */
class CardToIbanService
{
    public function convert(string $cardNumber): ?string
    {
        $serviceConfig = Config::get('services.card_to_iban');
        if (empty($serviceConfig['sc_api_key']) || empty($serviceConfig['sc_product_id'])) {
            Log::warning('CardToIban service not configured; skipping IBAN auto-completion.');

            return null;
        }

        $endpoint = config('podium.endpoints.do_service_call', '/srv/sc/nzh/doServiceCall');
        $url = rtrim(config('podium.base_url'), '/').$endpoint;

        $headers = [
            'accept' => 'application/json',
            '_token_' => (string) config('podium.business_token'),
            '_token_issuer_' => (string) config('podium.defaults._token_issuer', '1'),
            'scWrapper' => 'true',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $body = [
            'scProductId' => $serviceConfig['sc_product_id'],
            'scApiKey' => $serviceConfig['sc_api_key'],
            'card' => $cardNumber,
        ];

        try {
            $resp = Http::asForm()
                ->timeout(30)
                ->withHeaders($headers)
                ->post($url, $body);

            $json = $resp->json();

            if (! $resp->successful() || ($json['hasError'] ?? true)) {
                Log::warning('CardToIban inquiry failed', ['status' => $resp->status(), 'body' => $resp->body()]);

                return null;
            }

            $resultData = $json['result'] ?? [];

            // مثل بقیه سرویس‌های پادیوم، result ممکن است یک JSON-string تو در تو باشد
            if (isset($resultData['result']) && is_string($resultData['result'])) {
                $resultData = json_decode($resultData['result'], true) ?: [];
            }

            $iban = $resultData['IBAN']
                ?? $resultData['iban']
                ?? $resultData['sheba']
                ?? $resultData['shebaNumber']
                ?? null;

            if (! is_string($iban) || $iban === '') {
                return null;
            }

            // نرمال‌سازی به فرمت IR + 24 رقم
            $iban = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $iban));
            if (! str_starts_with($iban, 'IR')) {
                $iban = 'IR'.$iban;
            }

            return preg_match('/^IR\d{24}$/', $iban) ? $iban : null;

        } catch (\Throwable $e) {
            Log::warning('CardToIban inquiry exception: '.$e->getMessage());

            return null;
        }
    }
}
