<?php

namespace App\Services\PodiumServices;

use App\Services\PodiumServices\Concerns\TranslatesPodiumFailures;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PodiumBalanceService
{
    use TranslatesPodiumFailures;

    public function getBusinessBalances(?string $guildCode = null): array
    {
        return $this->guardPodiumCall('balances', fn () => $this->fetchBalances($guildCode));
    }

    private function fetchBalances(?string $guildCode): array
    {
        $url = rtrim(config('podium.base_url'), '/').config('podium.endpoints.do_service_call');

        $headers = [
            'accept' => 'application/json',
            '_token_' => (string) config('podium.business_token'),
            '_token_issuer_' => (string) config('podium.defaults._token_issuer'),
            'scWrapper' => 'false',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $scApiKey = (string) config('podium.sc_api_keys.balances');
        if ($scApiKey === '') {
            throw new \RuntimeException('Missing scApiKey for balances. Set PODIUM_SC_API_KEY_BALANCES in .env and run config:clear.');
        }

        $body = array_filter([
            'scProductId' => 39634,
            'scApiKey' => $scApiKey,
            'guildCode' => $guildCode,
        ], fn ($v) => $v !== null && $v !== '');

        $resp = Http::asForm()->timeout((int) config('podium.defaults.timeout'))->withHeaders($headers)->post($url, $body);
        $status = $resp->status();
        $raw = $resp->body();

        if (! $resp->successful()) {
            Log::error('[Podium][Balances] HTTP error', ['status' => $status, 'raw' => $raw]);
            throw new \RuntimeException('Podium HTTP error: '.$status);
        }

        // تلاش برای parse
        $json = $resp->json();
        if (! is_array($json)) {
            $json = json_decode($raw, true);
        }

        // نرمالایز تمام اشکال شناخته‌شده
        $json = $this->normalizePodiumBalanceResponse($json, $raw, $status);

        Log::info('[Podium][Balances] OK', ['status' => $status, 'ref' => $json['referenceNumber'] ?? null]);

        return $json;
    }

    private function normalizePodiumBalanceResponse($json, string $raw, int $status): array
    {
        if (! is_array($json)) {
            Log::error('[Podium][Balances] Non-JSON body', ['status' => $status, 'raw' => $raw]);
            throw new \RuntimeException('Unexpected Podium schema.');
        }

        if (isset($json['result']['result'])) {
            $inner = $json['result']['result'];
            if (is_string($inner)) {
                $decoded = json_decode($inner, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $json['result'] = $decoded;
                }
            } elseif (is_array($inner)) {
                $json['result'] = $inner;
            }
            unset($json['result']['result']);
        }

        if (array_key_exists('hasError', $json)) {
            if ($json['hasError'] === true) {
                $ref = $json['referenceNumber'] ?? null;
                $msg = $json['message'] ?? 'Unknown business error';
                $code = $json['errorCode'] ?? null;
                throw new \RuntimeException("Podium business error: {$msg} (code={$code}, ref={$ref})");
            }

            return $json;
        }

        $looksLikeBalance = isset($json['customerAmountSrvs']) || isset($json['mainBusinessAmountSrvs']);
        if ($looksLikeBalance) {
            return [
                'hasError' => false,
                'messageId' => 0,
                'referenceNumber' => $json['referenceNumber'] ?? null,
                'errorCode' => 0,
                'count' => 0,
                'ott' => $json['ott'] ?? null,
                'result' => $json,
            ];
        }
        if (isset($json['result']) && is_array($json['result']) &&
            (isset($json['result']['customerAmountSrvs']) || isset($json['result']['mainBusinessAmountSrvs']))
        ) {
            return [
                'hasError' => false,
                'messageId' => $json['messageId'] ?? 0,
                'referenceNumber' => $json['referenceNumber'] ?? null,
                'errorCode' => $json['errorCode'] ?? 0,
                'count' => $json['count'] ?? 0,
                'ott' => $json['ott'] ?? null,
                'result' => $json['result'],
            ];
        }

        Log::error('[Podium][Balances] Unexpected schema', ['status' => $status, 'json' => $json, 'raw' => $raw]);
        throw new \RuntimeException('Unexpected Podium schema.');
    }
}
