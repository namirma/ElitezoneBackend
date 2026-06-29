<?php

namespace App\Services\PodiumServices;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Podium "withdrawal from digital account (with tool selection)" service.
 *
 * Wraps the doServiceCall settlement endpoint dedicated to user withdrawals
 * (scProductId from config('podium.settlement.*')), kept separate from the
 * admin-facing PodiumSettlementService so the manual back-office route is
 * untouched.
 *
 * FAIL-SAFE: until config('podium.settlement.enabled') is true AND both
 * sc_product_id + sc_api_key are present, isConfigured() is false and settle()
 * must not be called (SettleWithdrawalAction guards on it). This mirrors the
 * PasargadGateway scaffold so a half-configured environment can never move money.
 */
class PodiumWithdrawalService
{
    /**
     * Whether the withdrawal settlement credentials are configured and enabled.
     */
    public function isConfigured(): bool
    {
        return (bool) config('podium.settlement.enabled')
            && filled(config('podium.settlement.sc_product_id'))
            && filled(config('podium.settlement.sc_api_key'));
    }

    /**
     * Submit a single withdrawal settlement to Podium.
     *
     * @param  array{amount:int,firstName:string,lastName?:?string,toolCode:string,toolId:string,uniqueId:string,description?:?string,detailType?:?string,paymentId?:?string}  $payload
     * @return array Normalised Podium response (always has a `hasError` key).
     *
     * @throws \RuntimeException on HTTP error, schema error, or business error.
     */
    public function settle(array $payload): array
    {
        $url = rtrim(config('podium.base_url'), '/').config('podium.endpoints.do_service_call');

        $headers = [
            'accept' => 'application/json',
            '_token_' => (string) config('podium.business_token'),
            '_token_issuer_' => (string) config('podium.defaults._token_issuer'),
            'scWrapper' => 'true',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $body = array_filter([
            'scProductId' => (string) config('podium.settlement.sc_product_id'),
            'scApiKey' => (string) config('podium.settlement.sc_api_key'),

            'amount' => (string) $payload['amount'],
            'firstName' => $payload['firstName'],
            'lastName' => $payload['lastName'] ?? null,
            'toolCode' => $payload['toolCode'],
            'toolId' => $payload['toolId'],

            'wallet' => $payload['wallet'] ?? config('podium.defaults.wallet'),
            'currencyCode' => $payload['currencyCode'] ?? config('podium.defaults.currency'),

            'description' => $payload['description'] ?? null,
            'detailType' => $payload['detailType'] ?? null,
            'paymentId' => $payload['paymentId'] ?? null,
            'uniqueId' => $payload['uniqueId'] ?? null,
        ], fn ($v) => ! is_null($v) && $v !== '');

        try {
            $resp = Http::asForm()
                ->timeout((int) config('podium.defaults.timeout'))
                ->withHeaders($headers)
                ->post($url, $body);

            $json = $resp->json();

            Log::info('[Podium][Withdrawal] response', [
                'status' => $resp->status(),
                'uniqueId' => $payload['uniqueId'] ?? null,
                'body' => $json,
            ]);

            if (! $resp->successful()) {
                throw new \RuntimeException('Podium HTTP error: '.$resp->status());
            }
            if (! is_array($json) || ! array_key_exists('hasError', $json)) {
                throw new \RuntimeException('Unexpected Podium schema.');
            }
            if ($json['hasError'] === true) {
                $ref = $json['referenceNumber'] ?? null;
                $msg = $json['message'] ?? data_get($json, 'result.message') ?? 'Unknown business error';
                $code = $json['errorCode'] ?? null;
                throw new \RuntimeException("Podium business error: {$msg} (code={$code}, ref={$ref})");
            }

            // Podium sometimes wraps the real result in a JSON string at result.result.
            $inner = data_get($json, 'result.result');
            if (is_string($inner)) {
                $decoded = json_decode($inner, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $json['result']['result'] = $decoded;
                }
            }

            return $json;
        } catch (ConnectionException $e) {
            Log::error('[Podium][Withdrawal] connection error', ['e' => $e->getMessage()]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[Podium][Withdrawal] error', ['e' => $e->getMessage()]);
            throw $e;
        }
    }
}
