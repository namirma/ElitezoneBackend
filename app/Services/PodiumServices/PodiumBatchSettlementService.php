<?php

namespace App\Services\PodiumServices;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PodiumBatchSettlementService
{
    public function settle(array $payload): array
    {
        $url = rtrim(config('podium.base_url'), '/') . config('podium.endpoints.do_service_call');

        $headers = [
            'accept'         => 'application/json',
            '_token_'        => (string) config('podium.business_token'),
            '_token_issuer_' => (string) config('podium.defaults._token_issuer'),
            'scWrapper'      => 'true',
            'Content-Type'   => 'application/x-www-form-urlencoded',
        ];

        $scApiKey = (string) config('podium.sc_api_keys.batch_settlement');
        if ($scApiKey === '') {
            throw new \RuntimeException('Missing scApiKey for batch settlement. Set PODIUM_SC_API_KEY_BATCH in .env and run config:clear.');
        }

        // list باید JSON string باشد
        $listJson = json_encode($payload['list'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($listJson === false) {
            throw new \RuntimeException('Cannot encode list JSON.');
        }

        $body = [
            'scProductId' => 1688541,
            'scApiKey'    => $scApiKey,
            'batchId'     => (string) $payload['batchId'],
            'list'        => $listJson,
        ];

        $resp   = Http::asForm()
            ->timeout((int) config('podium.defaults.timeout'))
            ->withHeaders($headers)
            ->post($url, $body);

        $status = $resp->status();
        $raw    = $resp->body();
        $json   = $resp->json();

        if (!$resp->successful()) {
            Log::error('[Podium][Batch] HTTP error', ['status' => $status, 'raw' => $raw]);
            throw new \RuntimeException('Podium HTTP error: '.$status);
        }

        if (!is_array($json)) {
            $json = json_decode($raw, true);
        }

        $json = $this->normalizeBatchResponse($json, $raw, $status);

        Log::info('[Podium][Batch] OK', ['status' => $status, 'ref' => $json['referenceNumber'] ?? null]);
        return $json;
    }

    private function normalizeBatchResponse($json, string $raw, int $status): array
    {
        if (!is_array($json)) {
            Log::error('[Podium][Batch] Non-JSON body', ['status' => $status, 'raw' => $raw]);
            throw new \RuntimeException('Unexpected Podium schema.');
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

        if (array_key_exists('hasError', $json)) {
            if ($json['hasError'] === true) {
                $ref  = $json['referenceNumber'] ?? null;
                $msg  = $json['message'] ?? 'Unknown business error';
                $code = $json['errorCode'] ?? null;
                throw new \RuntimeException("Podium business error: {$msg} (code={$code}, ref={$ref})");
            }
            return $json;
        }

        $looksLikeBatch = isset($json['batchId']) ||
                          isset($json['items'])   ||
                          isset($json['results']) ||
                          isset($json['successCount']) ||
                          isset($json['failedCount']);
        if ($looksLikeBatch) {
            return [
                'hasError'        => false,
                'messageId'       => $json['messageId'] ?? 0,
                'referenceNumber' => $json['referenceNumber'] ?? null,
                'errorCode'       => $json['errorCode'] ?? 0,
                'count'           => $json['count'] ?? 0,
                'ott'             => $json['ott'] ?? null,
                'result'          => $json,
            ];
        }

        if (isset($json['result']) && is_array($json['result'])) {
            return [
                'hasError'        => false,
                'messageId'       => $json['messageId'] ?? 0,
                'referenceNumber' => $json['referenceNumber'] ?? null,
                'errorCode'       => $json['errorCode'] ?? 0,
                'count'           => $json['count'] ?? 0,
                'ott'             => $json['ott'] ?? null,
                'result'          => $json['result'],
            ];
        }

        Log::error('[Podium][Batch] Unexpected schema', ['status' => $status, 'json' => $json, 'raw' => $raw]);
        throw new \RuntimeException('Unexpected Podium schema.');
    }
}
