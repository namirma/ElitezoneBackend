<?php

namespace App\Services\PodiumServices;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PodiumSettlementService
{
    public function singleSettlement(array $payload): array
    {
        $url    = rtrim(config('podium.base_url'), '/') . config('podium.endpoints.do_service_call');
        $header = [
            'accept'           => 'application/json',
            '_token_'          => (string) config('podium.business_token'),
            '_token_issuer_'   => (string) config('podium.defaults._token_issuer'),
            'scWrapper'        => config('podium.defaults.sc_wrapper') ? 'true' : 'false',
            'Content-Type'     => 'application/x-www-form-urlencoded',
        ];

        $body = array_filter([
            'scProductId'  => (string) config('podium.defaults.sc_product_id'),
            'scApiKey'     => (string) config('podium.sc_api_keys.single_settlement'),

            'amount'       => (string) $payload['amount'],
            'firstName'    => $payload['firstName'],
            'lastName'     => $payload['lastName'] ?? null,
            'toolCode'     => $payload['toolCode'],
            'toolId'       => $payload['toolId'],


            'wallet'       => $payload['wallet'] ?? config('podium.defaults.wallet'),
            'guildCode'    => $payload['guildCode'] ?? null,

            'currencyCode'    => $payload['currencyCode'] ?? config('podium.defaults.currency'),
            'description'     => $payload['description'] ?? null,
            'paymentId'       => $payload['paymentId'] ?? null,
            'uniqueId'        => $payload['uniqueId'] ?? null,
            'detailType'      => $payload['detailType'] ?? null,
            'sourceDescription' => $payload['sourceDescription'] ?? null,
        ], fn($v) => !is_null($v) && $v !== '');

        try {
            $resp = Http::asForm()
                ->timeout((int) config('podium.defaults.timeout'))
                ->withHeaders($header)
                ->post($url, $body);

            $json = $resp->json();

            Log::info('[Podium][SingleSettlement] response', [
                'status'  => $resp->status(),
                'body'    => $json,
            ]);

            if (!$resp->successful()) {
                throw new \RuntimeException('Podium HTTP error: ' . $resp->status());
            }
            if (!is_array($json) || !array_key_exists('hasError', $json)) {
                throw new \RuntimeException('Unexpected Podium schema.');
            }
            if ($json['hasError'] === true) {
                $ref  = $json['referenceNumber'] ?? null;
                $msg  = $json['message'] ?? data_get($json, 'result.message') ?? 'Unknown business error';
                $code = $json['errorCode'] ?? null;
                throw new \RuntimeException("Podium business error: {$msg} (code={$code}, ref={$ref})");
            }

            $inner = data_get($json, 'result.result');
            if (is_string($inner)) {
                $decoded = json_decode($inner, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $json['result']['result'] = $decoded;
                }
            }
            return $json;
        } catch (ConnectionException $e) {
            Log::error('[Podium][SingleSettlement] connection error', ['e' => $e->getMessage()]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[Podium][SingleSettlement] error', ['e' => $e->getMessage()]);
            throw $e;
        }
    }
}
