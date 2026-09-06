<?php

namespace App\Services\PodiumServices;

use App\Services\PodiumServices\Concerns\TranslatesPodiumFailures;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PodiumSettlementInquiryService
{
    use TranslatesPodiumFailures;

    public function search(array $filters): array
    {
        return $this->guardPodiumCall('settlement-inquiry', fn () => $this->runSearch($filters));
    }

    private function runSearch(array $filters): array
    {
        $url = rtrim(config('podium.base_url'), '/').config('podium.endpoints.do_service_call');

        $headers = [
            'accept' => 'application/json',
            '_token_' => (string) config('podium.business_token'),
            '_token_issuer_' => (string) config('podium.defaults._token_issuer'),
            'scWrapper' => 'true',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $scApiKey = (string) config('podium.sc_api_keys.settlement_inquiry');
        if ($scApiKey === '') {
            throw new \RuntimeException('Missing scApiKey for settlement inquiry. Set PODIUM_SC_API_KEY_INQUIRY in .env then config:clear.');
        }

        $body = array_filter(array_merge([
            'scProductId' => 29989,
            'scApiKey' => $scApiKey,
            'offset' => $filters['offset'] ?? 0,
            'size' => $filters['size'] ?? 20,
        ], [
            'uniqueId' => $filters['uniqueId'] ?? null,
            'id' => $filters['id'] ?? null,
            'statusCode' => $filters['statusCode'] ?? null,
            'toolId' => $filters['toolId'] ?? null,
            'toolCode' => $filters['toolCode'] ?? null,
            'currencyCode' => $filters['currencyCode'] ?? null,
            'fromAmount' => $filters['fromAmount'] ?? null,
            'toAmount' => $filters['toAmount'] ?? null,
            'fromDate' => $filters['fromDate'] ?? null,
            'toDate' => $filters['toDate'] ?? null,
            'firstName' => $filters['firstName'] ?? null,
            'lastName' => $filters['lastName'] ?? null,
            'invoiceId' => $filters['invoiceId'] ?? null,
            'paymentId' => $filters['paymentId'] ?? null,
        ]), fn ($v) => $v !== null && $v !== '');

        $resp = Http::asForm()->timeout((int) config('podium.defaults.timeout'))->withHeaders($headers)->post($url, $body);
        $status = $resp->status();
        $raw = $resp->body();
        $json = $resp->json();

        Log::info('[Podium][Inquiry] request', ['body' => $body]);
        Log::info('[Podium][Inquiry] response', ['status' => $status, 'json' => $json]);

        if (! $resp->successful()) {
            Log::error('[Podium][Inquiry] HTTP error', ['status' => $status, 'raw' => $raw]);
            throw new \RuntimeException('Podium HTTP error: '.$status);
        }

        if (! is_array($json)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $decoded;
            }
        }
        if (isset($json['result']['result'])) {
            $inner = $json['result']['result'];
            if (is_string($inner)) {
                $decoded = json_decode($inner, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $json['result'] = $decoded;
                }
            } elseif (is_array($inner)) {
                $json['result'] = $inner;
            }
            unset($json['result']['result']);
        }

        if (! array_key_exists('hasError', $json)) {
            $looksLikeList = isset($json['result']) || isset($json['settlementLogSrvs']) || isset($json['status']);
            if ($looksLikeList) {
                $json = [
                    'hasError' => false,
                    'messageId' => $json['messageId'] ?? 0,
                    'referenceNumber' => $json['referenceNumber'] ?? null,
                    'errorCode' => $json['errorCode'] ?? 0,
                    'count' => $json['count'] ?? 0,
                    'ott' => $json['ott'] ?? null,
                    'result' => $json['result'] ?? $json,
                ];
            }
        }

        if (($json['hasError'] ?? false) === true) {
            $ref = $json['referenceNumber'] ?? null;
            $msg = $json['message'] ?? 'Unknown business error';
            $code = $json['errorCode'] ?? null;
            throw new \RuntimeException("Podium business error: {$msg} (code={$code}, ref={$ref})");
        }

        return $json;
    }
}
